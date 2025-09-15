<?php
// save this as seo-api.php in your root directory

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

// Get the requested action
$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

switch ($action) {
    case 'list':
        listPages();
        break;
    case 'get':
        getPage($_GET['path'] ?? '/');
        break;
    case 'save':
        savePage($_POST);
        break;
    case 'scan':
        scanDirectory();
        break;
    case 'analyze':
        analyzePage($_GET['path'] ?? '/');
        break;
    default:
        echo json_encode(['error' => 'Invalid action']);
        break;
}

function listPages() {
    $pages = [];
    
    // Scan for HTML and PHP files
    $files = glob('./*.html');
    $phpFiles = glob('./*.php');
    $files = array_merge($files, $phpFiles);
    
    // Also check common directories
    $dirs = ['pages', 'blog', 'articles', 'products'];
    foreach ($dirs as $dir) {
        if (is_dir($dir)) {
            $htmlInDir = glob($dir . '/*.html');
            $phpInDir = glob($dir . '/*.php');
            $files = array_merge($files, $htmlInDir, $phpInDir);
        }
    }
    
    foreach ($files as $file) {
        // Clean up the file path for display
        $url = str_replace('./', '/', $file);
        $title = pathinfo($file, PATHINFO_FILENAME);
        $title = ucfirst(str_replace(['-', '_'], ' ', $title));
        
        // Get file modification time
        $lastModified = date('Y-m-d', filemtime($file));
        
        // Read the file to get basic SEO info
        $content = file_get_contents($file);
        $seoScore = calculateSEOScore($content);
        
        $pages[] = [
            'id' => md5($file),
            'url' => $url,
            'title' => $title,
            'status' => 'published',
            'lastModified' => $lastModified,
            'seoScore' => $seoScore,
            'filePath' => $file
        ];
    }
    
    echo json_encode(['pages' => $pages]);
}

function getPage($path) {
    // Default to index.html or index.php
    if ($path === '/' || $path === '') {
        if (file_exists('index.html')) {
            $path = 'index.html';
        } elseif (file_exists('index.php')) {
            $path = 'index.php';
        } else {
            // Find first HTML file
            $files = glob('./*.html');
            if (count($files) > 0) {
                $path = basename($files[0]);
            } else {
                $files = glob('./*.php');
                if (count($files) > 0) {
                    $path = basename($files[0]);
                } else {
                    echo json_encode(['error' => 'No default page found']);
                    return;
                }
            }
        }
    }
    
    // If path doesn't have extension, try with .html and .php
    if (!file_exists($path) && !preg_match('/\.(html|php)$/', $path)) {
        if (file_exists($path . '.html')) {
            $path = $path . '.html';
        } elseif (file_exists($path . '.php')) {
            $path = $path . '.php';
        }
    }
    
    if (!file_exists($path)) {
        echo json_encode(['error' => 'Page not found']);
        return;
    }
    
    $content = file_get_contents($path);
    $seoData = extractSEOData($content, $path);
    
    echo json_encode([
        'filePath' => $path,
        'content' => $content,
        'seoData' => $seoData
    ]);
}

function savePage($data) {
    $filePath = $data['filePath'] ?? '';
    
    if (!$filePath || !file_exists($filePath)) {
        echo json_encode(['error' => 'File not found']);
        return;
    }
    
    $content = file_get_contents($filePath);
    
    // Update SEO data in the file
    $updatedContent = updateSEOData($content, $data);
    
    // Backup the original file
    $backupPath = $filePath . '.bak';
    copy($filePath, $backupPath);
    
    // Save the updated content
    if (file_put_contents($filePath, $updatedContent)) {
        echo json_encode([
            'success' => true, 
            'message' => 'SEO settings saved successfully',
            'backup' => $backupPath
        ]);
    } else {
        echo json_encode(['error' => 'Failed to save file']);
    }
}

function scanDirectory() {
    $results = [
        'totalFiles' => 0,
        'htmlFiles' => 0,
        'phpFiles' => 0,
        'directories' => 0,
        'lastScan' => date('Y-m-d H:i:s')
    ];
    
    // Count files in current directory
    $htmlFiles = glob('./*.html');
    $phpFiles = glob('./*.php');
    $dirs = glob('*', GLOB_ONLYDIR);
    
    $results['htmlFiles'] = count($htmlFiles);
    $results['phpFiles'] = count($phpFiles);
    $results['directories'] = count($dirs);
    $results['totalFiles'] = $results['htmlFiles'] + $results['phpFiles'];
    
    echo json_encode($results);
}

function analyzePage($path) {
    if ($path === '/' || $path === '') {
        $path = file_exists('index.html') ? 'index.html' : (file_exists('index.php') ? 'index.php' : '');
    }
    
    if (!$path || !file_exists($path)) {
        echo json_encode(['error' => 'Page not found']);
        return;
    }
    
    $content = file_get_contents($path);
    $issues = [];
    $score = 100;
    
    // Check for title tag
    if (!preg_match('/<title>(.*?)<\/title>/i', $content, $matches)) {
        $issues[] = ['type' => 'error', 'message' => 'Missing title tag'];
        $score -= 15;
    } else {
        $title = $matches[1];
        if (strlen($title) < 10) {
            $issues[] = ['type' => 'warning', 'message' => 'Title tag is too short (less than 10 characters)'];
            $score -= 5;
        } elseif (strlen($title) > 60) {
            $issues[] = ['type' => 'warning', 'message' => 'Title tag is too long (more than 60 characters)'];
            $score -= 5;
        } else {
            $issues[] = ['type' => 'success', 'message' => 'Title tag is optimal length'];
        }
    }
    
    // Check for meta description
    if (!preg_match('/<meta\s+name=["\']description["\']\s+content=["\'](.*?)["\']/i', $content, $matches)) {
        $issues[] = ['type' => 'error', 'message' => 'Missing meta description'];
        $score -= 15;
    } else {
        $description = $matches[1];
        if (strlen($description) < 50) {
            $issues[] = ['type' => 'warning', 'message' => 'Meta description is too short (recommended: 50-160 characters)'];
            $score -= 5;
        } elseif (strlen($description) > 160) {
            $issues[] = ['type' => 'warning', 'message' => 'Meta description is too long (recommended: 50-160 characters)'];
            $score -= 5;
        } else {
            $issues[] = ['type' => 'success', 'message' => 'Meta description is optimal length'];
        }
    }
    
    // Check for H1 tag
    if (!preg_match('/<h1[^>]*>(.*?)<\/h1>/i', $content)) {
        $issues[] = ['type' => 'warning', 'message' => 'No H1 tag found on page'];
        $score -= 10;
    } else {
        $issues[] = ['type' => 'success', 'message' => 'H1 tag found'];
    }
    
    // Check for images without alt tags
    preg_match_all('/<img\s+[^>]*>/i', $content, $imgMatches);
    $imagesWithoutAlt = 0;
    foreach ($imgMatches[0] as $imgTag) {
        if (!preg_match('/alt\s*=\s*["\'][^"\']*["\']/i', $imgTag)) {
            $imagesWithoutAlt++;
        }
    }
    
    if ($imagesWithoutAlt > 0) {
        $issues[] = ['type' => 'error', 'message' => "Missing alt tags on {$imagesWithoutAlt} images"];
        $score -= ($imagesWithoutAlt * 3);
    } else if (count($imgMatches[0]) > 0) {
        $issues[] = ['type' => 'success', 'message' => 'All images have alt tags'];
    }
    
    // Check for canonical URL
    if (!preg_match('/<link\s+rel=["\']canonical["\']\s+href=["\'][^"\']*["\']/i', $content)) {
        $issues[] = ['type' => 'warning', 'message' => 'Missing canonical URL'];
        $score -= 5;
    } else {
        $issues[] = ['type' => 'success', 'message' => 'Canonical URL found'];
    }
    
    // Check for meta robots
    if (!preg_match('/<meta\s+name=["\']robots["\']\s+content=["\'][^"\']*["\']/i', $content)) {
        $issues[] = ['type' => 'info', 'message' => 'No robots meta tag (default is index, follow)'];
    } else {
        $issues[] = ['type' => 'success', 'message' => 'Robots meta tag found'];
    }
    
    // Ensure score doesn't go below 0
    $score = max(0, $score);
    
    echo json_encode([
        'score' => $score,
        'issues' => $issues,
        'filePath' => $path
    ]);
}

function extractSEOData($content, $filePath) {
    $seoData = [
        'title' => '',
        'description' => '',
        'keywords' => '',
        'canonicalUrl' => '',
        'robots' => 'index, follow',
        'ogTitle' => '',
        'ogDescription' => '',
        'ogImage' => '',
        'twitterCard' => 'summary',
        'twitterTitle' => '',
        'twitterDescription' => '',
        'twitterImage' => '',
        'schemaType' => 'Website',
        'schemaData' => '{}'
    ];
    
    // Extract title
    if (preg_match('/<title>(.*?)<\/title>/i', $content, $matches)) {
        $seoData['title'] = $matches[1];
    }
    
    // Extract meta description
    if (preg_match('/<meta\s+name=["\']description["\']\s+content=["\'](.*?)["\']/i', $content, $matches)) {
        $seoData['description'] = $matches[1];
    }
    
    // Extract meta keywords
    if (preg_match('/<meta\s+name=["\']keywords["\']\s+content=["\'](.*?)["\']/i', $content, $matches)) {
        $seoData['keywords'] = $matches[1];
    }
    
    // Extract canonical URL
    if (preg_match('/<link\s+rel=["\']canonical["\']\s+href=["\'](.*?)["\']/i', $content, $matches)) {
        $seoData['canonicalUrl'] = $matches[1];
    }
    
    // Extract robots
    if (preg_match('/<meta\s+name=["\']robots["\']\s+content=["\'](.*?)["\']/i', $content, $matches)) {
        $seoData['robots'] = $matches[1];
    }
    
    // Extract Open Graph tags
    if (preg_match('/<meta\s+property=["\']og:title["\']\s+content=["\'](.*?)["\']/i', $content, $matches)) {
        $seoData['ogTitle'] = $matches[1];
    }
    if (preg_match('/<meta\s+property=["\']og:description["\']\s+content=["\'](.*?)["\']/i', $content, $matches)) {
        $seoData['ogDescription'] = $matches[1];
    }
    if (preg_match('/<meta\s+property=["\']og:image["\']\s+content=["\'](.*?)["\']/i', $content, $matches)) {
        $seoData['ogImage'] = $matches[1];
    }
    
    // Extract Twitter tags
    if (preg_match('/<meta\s+name=["\']twitter:card["\']\s+content=["\'](.*?)["\']/i', $content, $matches)) {
        $seoData['twitterCard'] = $matches[1];
    }
    if (preg_match('/<meta\s+name=["\']twitter:title["\']\s+content=["\'](.*?)["\']/i', $content, $matches)) {
        $seoData['twitterTitle'] = $matches[1];
    }
    if (preg_match('/<meta\s+name=["\']twitter:description["\']\s+content=["\'](.*?)["\']/i', $content, $matches)) {
        $seoData['twitterDescription'] = $matches[1];
    }
    if (preg_match('/<meta\s+name=["\']twitter:image["\']\s+content=["\'](.*?)["\']/i', $content, $matches)) {
        $seoData['twitterImage'] = $matches[1];
    }
    
    // Extract schema markup
    if (preg_match('/<script\s+type=["\']application\/ld\+json["\'].*?>(.*?)<\/script>/is', $content, $matches)) {
        $seoData['schemaData'] = trim($matches[1]);
        // Try to extract schema type
        if (preg_match('/"@type"\s*:\s*["\'](.*?)["\']/i', $matches[1], $typeMatches)) {
            $seoData['schemaType'] = $typeMatches[1];
        }
    }
    
    return $seoData;
}

function updateSEOData($content, $newData) {
    // Remove existing SEO tags
    $content = preg_replace('/<title>.*?<\/title>/i', '', $content);
    $content = preg_replace('/<meta\s+name=["\']description["\']\s+content=["\'].*?["\']\s*\/?>/i', '', $content);
    $content = preg_replace('/<meta\s+name=["\']keywords["\']\s+content=["\'].*?["\']\s*\/?>/i', '', $content);
    $content = preg_replace('/<link\s+rel=["\']canonical["\']\s+href=["\'].*?["\']\s*\/?>/i', '', $content);
    $content = preg_replace('/<meta\s+name=["\']robots["\']\s+content=["\'].*?["\']\s*\/?>/i', '', $content);
    
    // Remove Open Graph tags
    $content = preg_replace('/<meta\s+property=["\']og:title["\']\s+content=["\'].*?["\']\s*\/?>/i', '', $content);
    $content = preg_replace('/<meta\s+property=["\']og:description["\']\s+content=["\'].*?["\']\s*\/?>/i', '', $content);
    $content = preg_replace('/<meta\s+property=["\']og:image["\']\s+content=["\'].*?["\']\s*\/?>/i', '', $content);
    $content = preg_replace('/<meta\s+property=["\']og:type["\']\s+content=["\'].*?["\']\s*\/?>/i', '', $content);
    $content = preg_replace('/<meta\s+property=["\']og:url["\']\s+content=["\'].*?["\']\s*\/?>/i', '', $content);
    
    // Remove Twitter tags
    $content = preg_replace('/<meta\s+name=["\']twitter:card["\']\s+content=["\'].*?["\']\s*\/?>/i', '', $content);
    $content = preg_replace('/<meta\s+name=["\']twitter:title["\']\s+content=["\'].*?["\']\s*\/?>/i', '', $content);
    $content = preg_replace('/<meta\s+name=["\']twitter:description["\']\s+content=["\'].*?["\']\s*\/?>/i', '', $content);
    $content = preg_replace('/<meta\s+name=["\']twitter:image["\']\s+content=["\'].*?["\']\s*\/?>/i', '', $content);
    
    // Remove schema markup
    $content = preg_replace('/<script\s+type=["\']application\/ld\+json["\'].*?>.*?<\/script>/is', '', $content);
    
    // Clean up multiple empty lines
    $content = preg_replace('/\n\s*\n\s*\n/', "\n\n", $content);
    
    // Insert new SEO data
    $headInsert = '';
    
    // Add title
    if (!empty($newData['title'])) {
        $headInsert .= "<title>" . htmlspecialchars($newData['title']) . "</title>\n";
    }
    
    // Add meta description
    if (!empty($newData['description'])) {
        $headInsert .= '<meta name="description" content="' . htmlspecialchars($newData['description']) . "\">\n";
    }
    
    // Add meta keywords
    if (!empty($newData['keywords'])) {
        $headInsert .= '<meta name="keywords" content="' . htmlspecialchars($newData['keywords']) . "\">\n";
    }
    
    // Add canonical URL
    if (!empty($newData['canonicalUrl'])) {
        $headInsert .= '<link rel="canonical" href="' . htmlspecialchars($newData['canonicalUrl']) . "\">\n";
    }
    
    // Add robots
    if (!empty($newData['robots'])) {
        $headInsert .= '<meta name="robots" content="' . htmlspecialchars($newData['robots']) . "\">\n";
    }
    
    // Add Open Graph tags
    if (!empty($newData['ogTitle'])) {
        $headInsert .= '<meta property="og:title" content="' . htmlspecialchars($newData['ogTitle']) . "\">\n";
    }
    if (!empty($newData['ogDescription'])) {
        $headInsert .= '<meta property="og:description" content="' . htmlspecialchars($newData['ogDescription']) . "\">\n";
    }
    if (!empty($newData['ogImage'])) {
        $headInsert .= '<meta property="og:image" content="' . htmlspecialchars($newData['ogImage']) . "\">\n";
    }
    if (!empty($newData['ogTitle']) || !empty($newData['ogDescription'])) {
        $headInsert .= '<meta property="og:type" content="website">' . "\n";
        $currentUrl = "https://" . ($_SERVER['HTTP_HOST'] ?? 'example.com') . $_SERVER['REQUEST_URI'];
        $headInsert .= '<meta property="og:url" content="' . htmlspecialchars($currentUrl) . "\">\n";
    }
    
    // Add Twitter tags
    if (!empty($newData['twitterCard'])) {
        $headInsert .= '<meta name="twitter:card" content="' . htmlspecialchars($newData['twitterCard']) . "\">\n";
    }
    if (!empty($newData['twitterTitle'])) {
        $headInsert .= '<meta name="twitter:title" content="' . htmlspecialchars($newData['twitterTitle']) . "\">\n";
    }
    if (!empty($newData['twitterDescription'])) {
        $headInsert .= '<meta name="twitter:description" content="' . htmlspecialchars($newData['twitterDescription']) . "\">\n";
    }
    if (!empty($newData['twitterImage'])) {
        $headInsert .= '<meta name="twitter:image" content="' . htmlspecialchars($newData['twitterImage']) . "\">\n";
    }
    
    // Add schema markup
    if (!empty($newData['schemaData']) && $newData['schemaData'] !== '{}') {
        $headInsert .= "<script type=\"application/ld+json\">\n" . $newData['schemaData'] . "\n</script>\n";
    }
    
    // Insert into head section
    if (preg_match('/<head[^>]*>(.*?)<\/head>/is', $content, $headMatches)) {
        $headContent = $headMatches[1];
        $newHeadContent = $headInsert . $headContent;
        $content = str_replace($headMatches[0], '<head>' . $newHeadContent . '</head>', $content);
    } else {
        // If no head tag, add one
        if (strpos($content, '<html') !== false) {
            $content = preg_replace('/(<html[^>]*>)/i', '$1<head>' . $headInsert . "</head>", $content, 1);
        } else {
            // Add at the beginning
            $content = $headInsert . $content;
        }
    }
    
    return $content;
}

function calculateSEOScore($content) {
    $score = 50; // Start with 50
    
    // Check for title
    if (preg_match('/<title>(.*?)<\/title>/i', $content, $matches)) {
        $title = $matches[1];
        if (strlen($title) >= 10 && strlen($title) <= 60) {
            $score += 15;
        } else {
            $score += 7;
        }
    }
    
    // Check for description
    if (preg_match('/<meta\s+name=["\']description["\']\s+content=["\'](.*?)["\']/i', $content, $matches)) {
        $description = $matches[1];
        if (strlen($description) >= 50 && strlen($description) <= 160) {
            $score += 15;
        } else {
            $score += 7;
        }
    }
    
    // Check for H1
    if (preg_match('/<h1[^>]*>.*?<\/h1>/i', $content)) {
        $score += 10;
    }
    
    // Check for images with alt tags
    preg_match_all('/<img\s+[^>]*>/i', $content, $imgMatches);
    if (count($imgMatches[0]) > 0) {
        $allHaveAlt = true;
        foreach ($imgMatches[0] as $imgTag) {
            if (!preg_match('/alt\s*=\s*["\'][^"\']*["\']/i', $imgTag)) {
                $allHaveAlt = false;
                break;
            }
        }
        if ($allHaveAlt) {
            $score += 10;
        }
    } else {
        $score += 5; // No images is fine
    }
    
    // Check for canonical
    if (preg_match('/<link\s+rel=["\']canonical["\']\s+href=["\'][^"\']*["\']/i', $content)) {
        $score += 5;
    }
    
    return min(100, $score);
}
?>
