<?php

// --- Configuration ---
// !! VERIFY THIS PATH !! Use the output of `which ffmpeg` or `command -v ffmpeg` run via SSH.
define('FFMPEG_PATH', '/bin/ffmpeg');
// Directory for final videos, relative to this script's location
define('DEFAULT_OUTPUT_FOLDER', 'merged_videos');
// Directory for temporary downloaded files & lists, relative to this script
define('TEMP_DIR', __DIR__ . '/temp_video_files');
// Set higher limits for potentially long video processing
// !! Ensure these values (or higher) are also set in php.ini / FPM config !!
define('MAX_EXECUTION_TIME', 600); // 10 minutes, adjust as needed
define('MEMORY_LIMIT', '1024M'); // Adjust based on video size & server RAM
// --- End Configuration ---

// --- Global Variable for Log Path ---
$globalLogFilePath = null;

// --- Helper Functions ---

/**
 * Writes a message to the request-specific log file or falls back to PHP error_log.
 */
function writeToLog($message) {
    global $globalLogFilePath;
    $targetLogPath = $globalLogFilePath; // Use the globally set path for this request

    if ($targetLogPath) {
        $timestamp = date('Y-m-d H:i:s');
        $logDir = dirname($targetLogPath);
        if (!is_dir($logDir)) {
            // Use @ to suppress warnings if directory already exists due to race condition
            @mkdir($logDir, 0775, true);
        }
        // Append message to the log file, use file locking for safety
        file_put_contents($targetLogPath, "[$timestamp] " . $message . "\n", FILE_APPEND | LOCK_EX);
    } else {
        // Fallback if called before log path is set (e.g., very early error)
        error_log("VideoMerger Script (Log Path Not Set): " . $message);
    }
}

/**
 * Centralized error handler: Logs details, sends JSON response, exits.
 */
function handleErrorAndLog($errorMessage, $httpCode, $logContext = null) {
    global $globalLogFilePath; // Use the global path if set
    $logMessage = "Error (HTTP $httpCode): $errorMessage";

    // Add context, truncating if it's too long
    if ($logContext) {
         $contextStr = is_string($logContext) ? $logContext : print_r($logContext, true);
         // Limit context length in log to prevent huge log files
         if (strlen($contextStr) > 2048) {
              $contextStr = substr($contextStr, 0, 2048) . "... (truncated)";
         }
        $logMessage .= "\nContext/Details:\n" . $contextStr;
    }

    // Log the error using the dedicated function
    writeToLog($logMessage);

    // Ensure JSON header is set correctly, but only if headers not already sent
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    http_response_code($httpCode);
    echo json_encode(["error" => $errorMessage]);
    exit; // Terminate script execution
}

/**
 * Executes FFmpeg command using exec(), checks exit code, returns error message or null.
 */
function executeCommand($command) {
    $ffmpegPath = FFMPEG_PATH; // Get path from constant
    // Ensure the command string passed doesn't start with the path already
    if (strpos($command, $ffmpegPath) === 0) {
        $fullCommand = $command; // Path already included
    } else {
        $fullCommand = $ffmpegPath . ' ' . $command; // Prepend the path
    }

    writeToLog("Executing FFmpeg: " . $fullCommand);

    $output_lines = [];
    $return_var = -1; // Initialize return var

    exec($fullCommand . ' 2>&1', $output_lines, $return_var);
    $full_output = implode("\n", $output_lines);

    if ($return_var !== 0) {
        writeToLog("FFmpeg Execution Failed (Return Code: $return_var). Full Output:\n" . $full_output);

        if ($return_var === 127 || stripos($full_output, 'No such file or directory') !== false || stripos($full_output, 'not found') !== false ) {
            if (stripos($full_output, $ffmpegPath) !== false && (stripos($full_output, 'No such file or directory') !== false || stripos($full_output, 'not found') !== false)) {
                return "FFmpeg execution failed: Command not found or inaccessible. Path used: '$ffmpegPath'. Verify path, OS permissions, CageFS, SELinux.";
            }
        }

        $error_message = "FFmpeg failed with exit code $return_var."; // Default error
        foreach ($output_lines as $line) {
            if (preg_match('/(permission denied|invalid argument|error opening|fail|unable to open|could not open|codec not found|conversion failed|no such file or directory|not found|muxing overhead|error while|does not match any stream)/i', trim($line))) {
                 if(preg_match('/^(error|fatal|panic):/i', trim($line))){
                     $error_message = "FFmpeg execution failed: " . trim($line);
                      break; 
                 } else if ($error_message === "FFmpeg failed with exit code $return_var."){
                     $error_message = "FFmpeg potentially failed: " . trim($line);
                 }
            }
        }
        
        if ($error_message === "FFmpeg failed with exit code $return_var.") {
             $output_tail = implode("\n", array_slice($output_lines, -5)); // Get last 5 lines
             $error_message .= " Full output logged. Tail of output: " . substr(preg_replace('/\s+/', ' ', $output_tail), 0, 350);
        }
        return $error_message;
    }
    return null; // Indicate success
}

/**
 * Downloads a file from a URL using cURL. Returns temp path or throws Exception.
 */
function downloadFileHelper($url, $fileTypePrefix, &$tempFiles) {
    if (empty($url)) {
        return null;
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        throw new Exception("Invalid URL provided for $fileTypePrefix: $url");
    }

    writeToLog("Downloading $fileTypePrefix file from $url");
    $parsedUrl = parse_url($url);
    $pathInfo = pathinfo($parsedUrl['path'] ?? '');
    $ext = strtolower($pathInfo['extension'] ?? '');

    $allowedVideoExt = ['mp4', 'mov', 'avi', 'webm', 'mkv', 'flv', 'wmv'];
    $allowedAudioExt = ['mp3', 'wav', 'aac', 'm4a', 'ogg', 'flac'];
    $defaultExt = 'tmp'; 

    if (substr($fileTypePrefix, 0, 3) === 'vid' && in_array($ext, $allowedVideoExt)) {
        $defaultExt = $ext;
    } elseif ($fileTypePrefix == 'audio' && in_array($ext, $allowedAudioExt)) {
         $defaultExt = $ext;
    } elseif (substr($fileTypePrefix, 0, 3) === 'vid') {
         $defaultExt = 'mp4'; 
    } elseif ($fileTypePrefix == 'audio') {
         $defaultExt = 'mp3'; 
    }

    $tempFilename = generateFilenameBase($fileTypePrefix) . '.' . $defaultExt;
    $tempPath = TEMP_DIR . '/' . $tempFilename;

    $fp = fopen($tempPath, 'wb');
    if (!$fp) {
        throw new Exception("Failed to open temporary file for writing: $tempPath");
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_FILE, $fp);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); 
    curl_setopt($ch, CURLOPT_MAXREDIRS, 10);        
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);   
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);         
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); 
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_FAILONERROR, true);    

    curl_exec($ch); 

    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch); 
    $downloadedSize = curl_getinfo($ch, CURLINFO_SIZE_DOWNLOAD_T); 

    curl_close($ch);
    fclose($fp); 

    $actualFileSize = file_exists($tempPath) ? filesize($tempPath) : -1;

    if (!empty($curlError) || $httpCode >= 400 || $actualFileSize <= 0 || ($downloadedSize > 0 && $actualFileSize != $downloadedSize)) {
        $sizeInfo = "(Disk: $actualFileSize / Header: $downloadedSize)";
        @unlink($tempPath); 
        throw new Exception("Failed to download $fileTypePrefix file from: $url (HTTP: $httpCode, Size: $sizeInfo, Error: $curlError)");
    }

    $tempFiles[] = $tempPath; 
    writeToLog("$fileTypePrefix file download complete: $tempPath");
    return $tempPath;
}

/**
 * Deletes temporary files created during the process.
 */
function cleanupTempFiles(array $files) {
    $filesToClean = array_filter($files);
    if(empty($filesToClean)) return;

    writeToLog("Cleaning up temp files: " . implode(', ', $filesToClean));
    foreach ($filesToClean as $file) {
        if (file_exists($file)) {
            @unlink($file); 
        }
    }
}

/**
 * Generates a reasonably unique filename base using time and random bytes.
 */
function generateFilenameBase($prefix = 'file') {
    $safePrefix = preg_replace('/[^a-zA-Z0-9_-]/', '', $prefix);
    return $safePrefix . '_' . time() . '_' . bin2hex(random_bytes(4));
}

// --- Script Execution Starts ---

$scriptName = basename($_SERVER['PHP_SELF']);
$scriptDirPath = dirname($_SERVER['SCRIPT_NAME']);
$scriptDirPath = ($scriptDirPath == '/' || $scriptDirPath == '\\') ? '' : $scriptDirPath;
$protocol = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http");
$host = $_SERVER['HTTP_HOST'];
$serverUrl = $protocol . "://" . $host . $scriptDirPath . "/{$scriptName}";
$basePublicUrl = $protocol . "://" . $host . $scriptDirPath;

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    header('Content-Type: text/html; charset=utf-8');

    $ffmpegInstalled = 'Checking...'; $curlInstalled = 'Checking...'; $permissionsOk = false; $permissions = [];
    $ffmpegVersionOutput = []; $ffmpegReturnCode = -1; @exec(FFMPEG_PATH . ' -version 2>&1', $ffmpegVersionOutput, $ffmpegReturnCode);
    $ffmpegVersionOutput = implode("\n", $ffmpegVersionOutput);
    if ($ffmpegReturnCode === 0 && stripos($ffmpegVersionOutput, 'ffmpeg version') !== false) { $ffmpegInstalled = 'Installed ✅'; }
    elseif ($ffmpegReturnCode === 127 || stripos($ffmpegVersionOutput, 'No such file') !== false || stripos($ffmpegVersionOutput, 'not found') !== false ) { $ffmpegInstalled = 'Not Found ❌ (Path: `' . FFMPEG_PATH . '` incorrect, or FFmpeg not installed/accessible to web user. Check CageFS/SELinux/Permissions).'; }
    else { if ($ffmpegReturnCode === -1 && empty($ffmpegVersionOutput)) { $ffmpegInstalled = 'Unknown Status ❓ (`exec` might be disabled in php.ini `disable_functions`).'; }
           else { $ffmpegInstalled = 'Unknown Status ❓ (Command failed or output unexpected. RC: ' . $ffmpegReturnCode . ', Output: ' . htmlspecialchars(substr($ffmpegVersionOutput,0,100)).'...)'; } }
    $curlInstalled = function_exists('curl_version') ? 'Installed ✅' : 'Not Installed ❌ (Required for downloads)';
    $outputDir = __DIR__ . '/' . DEFAULT_OUTPUT_FOLDER; $tempDirCheck = TEMP_DIR;
    if (!is_dir($outputDir)) @mkdir($outputDir, 0775, true); if (!is_dir($tempDirCheck)) @mkdir($tempDirCheck, 0775, true);
    $scriptDirWritable = is_writable(__DIR__); $outputDirWritable = is_writable($outputDir); $tempDirWritable = is_writable($tempDirCheck);
    $permissions[] = 'Script Dir (' . basename(__DIR__) . '): ' . ($scriptDirWritable ? 'Writable ✅' : 'Not Writable ❌');
    $permissions[] = 'Output Dir (' . DEFAULT_OUTPUT_FOLDER . '): ' . ($outputDirWritable ? 'Writable ✅' : 'Not Writable ❌');
    $permissions[] = 'Temp Dir (' . basename(TEMP_DIR) . '): ' . ($tempDirWritable ? 'Writable ✅' : 'Not Writable ❌');
    $permissionsOk = $outputDirWritable && $tempDirWritable;
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Video Merging API (JSON Input)</title>
        <link href='https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap' rel='stylesheet'>
        <style>
            body { font-family: 'Roboto', sans-serif; background-color: #f3f4f6; margin: 0; padding: 0; color: #333; line-height: 1.6; }
            h1 { background-color: #2563eb; color: white; padding: 20px; text-align: center; margin: 0; font-weight: 500; }
            div.container { padding: 20px 30px 40px 30px; margin: 30px auto; max-width: 900px; background: white; border-radius: 10px; box-shadow: 0 0 15px rgba(0, 0, 0, 0.1); }
            h2 { border-bottom: 2px solid #e5e7eb; padding-bottom: 10px; margin-top: 30px; color: #1e3a8a; font-weight: 500;}
            code, pre { background-color: #f0f4f8; padding: 12px 15px; border: 1px solid #d1d5db; border-radius: 5px; display: block; margin-bottom: 15px; white-space: pre-wrap; word-wrap: break-word; font-family: Consolas, Monaco, 'Andale Mono', 'Ubuntu Mono', monospace; font-size: 0.9em; color: #1f2937; }
            ul { list-style-type: disc; margin-left: 20px; padding-left: 5px;}
            li { margin-bottom: 10px; }
            strong { color: #1e40af; font-weight: 500; }
            button { padding: 10px 20px; background-color: #2563eb; color: white; border: none; border-radius: 5px; cursor: pointer; margin-top: 10px; font-size: 0.95em; }
            button:hover { background-color: #1e40af; }
            .note { background-color: #fffbeb; border-left: 4px solid #f59e0b; padding: 12px 15px; margin: 20px 0; border-radius: 4px;}
            .error { color: #dc2626; font-weight: bold; }
            .success { color: #059669; font-weight: bold; }
            .config-path { font-style: italic; color: #555; background-color: #e5e7eb; padding: 2px 4px; border-radius: 3px;}
            .status-list li { margin-bottom: 5px; list-style-type: none;}
            .status-icon { margin-right: 8px; display: inline-block; width: 20px; text-align: center;}
            .attribution a { color: #2563eb; text-decoration: none; }
            .attribution a:hover { text-decoration: underline; }
        </style>
    </head>
    <body>
        <h1>Video Merging API (JSON Input)</h1>
        <div class="container">
            <p style="text-align:center;">
                <img src="https://blog.automation-tribe.com/wp-content/uploads/2025/05/logo-automation-tribe-750.webp" alt="Automation Tribe Logo" style="max-width: 200px; margin-bottom: 10px;">
            </p>
            <p class="attribution" style="text-align:center; font-size: 0.9em; margin-bottom: 25px;">
                This API endpoint was made by <a href="https://www.automation-tribe.com" target="_blank" rel="noopener noreferrer">Automation Tribe</a>.<br>
                Join our community at <a href="https://www.skool.com/automation-tribe" target="_blank" rel="noopener noreferrer">https://www.skool.com/automation-tribe</a>.
            </p>

            <p>This API merges multiple videos, optionally adding a new audio track (replacing any existing audio). Input is via a <strong>JSON payload</strong>.</p>
            <p class="note"><strong>Logging:</strong> On processing errors, a detailed log file (e.g., <code>output_video_name_timestamp.log</code>) will be created in the output folder (<code><?php echo htmlspecialchars(DEFAULT_OUTPUT_FOLDER); ?></code>). **Check this log for full FFmpeg error details!**</p>

            <h2>Server Status</h2>
            <ul class="status-list">
                 <li><span class="status-icon">🔧</span><strong>FFmpeg Path Configured:</strong> <code class="config-path"><?php echo htmlspecialchars(FFMPEG_PATH); ?></code></li>
                <li><span class="<?php echo strpos($ffmpegInstalled, '✅') !== false ? 'success' : 'error'; ?>"><span class="status-icon"><?php echo strpos($ffmpegInstalled, '✅') !== false ? '✅' : '❓'; ?></span><strong>FFmpeg Status:</strong> <?php echo $ffmpegInstalled; ?></span></li>
                <li><span class="<?php echo strpos($curlInstalled, '✅') !== false ? 'success' : 'error'; ?>"><span class="status-icon"><?php echo strpos($curlInstalled, '✅') !== false ? '✅' : '❌'; ?></span><strong>PHP cURL Extension:</strong> <?php echo $curlInstalled; ?></span></li>
                <li><span class="status-icon">📁</span><strong>Folder Permissions:</strong>
                    <ul style="margin-left: 10px; margin-top: 5px;">
                        <?php foreach ($permissions as $perm): ?>
                        <li><span class="<?php echo strpos($perm, '✅') !== false ? 'success' : 'error'; ?>"><span class="status-icon"><?php echo strpos($perm, '✅') !== false ? '✅' : '❌'; ?></span><?php echo $perm; ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                </li>
            </ul>
             <?php if (strpos($ffmpegInstalled, '❌') !== false || strpos($ffmpegInstalled, '❓') !== false || strpos($curlInstalled, '❌') !== false || !$permissionsOk): ?>
                <p class="error note"><strong>Action Required:</strong> Please address the items marked with ❌ or ❓ above. Ensure FFmpeg is installed & accessible via the configured path, PHP cURL is enabled, and Temp/Output directories are writable. Check PHP `disable_functions` if FFmpeg status is unknown/`exec` fails. Consult server logs for details.</p>
            <?php endif; ?>

            <h2>API Usage</h2>
            <h3>Endpoint</h3>
            <code><?php echo htmlspecialchars($serverUrl); ?></code>

            <h3>HTTP Method</h3>
            <code>POST</code>

            <h3>Headers</h3>
            <code>Content-Type: application/json</code>

            <h3>Request Body (JSON Payload)</h3>
            <p>Send a JSON object with the following structure:</p>
            <?php
            $exampleJsonPayload = [
                "videos" => [
                    "https://REQUIRED_URL/path/to/video1.mp4",
                    "https://REQUIRED_URL/path/to/video2.mp4",
                ],
                "audio" => "https://OPTIONAL_URL/path/to/new_audio.mp3", // null or omit if not needed
                "name" => "my-merged-video", // Optional base name
                "folder" => DEFAULT_OUTPUT_FOLDER // Optional output subfolder
            ];
            ?>
            <pre><code><?php echo htmlspecialchars(json_encode($exampleJsonPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></code></pre>
            <ul>
                <li><code>videos</code> (<strong>Required</strong>): Array of video URLs (strings).</li>
                <li><code>audio</code> (Optional): URL to a new audio track (string or null). If provided, this audio will replace any existing audio in the merged video.</li>
                <li><code>name</code> (Optional): Output base filename (string).</li>
                <li><code>folder</code> (Optional): Output folder name (string).</li>
            </ul>

            <h3>Success Response (JSON)</h3>
            <pre><code><?php echo htmlspecialchars(json_encode(["url" => rtrim($basePublicUrl, '/') . '/' . DEFAULT_OUTPUT_FOLDER . "/your-video-name_timestamp_random.mp4"], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); ?></code></pre>

            <h3>Error Response (JSON)</h3>
            <pre><code><?php echo htmlspecialchars(json_encode(["error" => "Concise error message. Check server .log file for full FFmpeg output."], JSON_PRETTY_PRINT)); ?></code></pre>
            <p>If an error occurs, also check the corresponding <code>.log</code> file in the output folder (<code><?php echo htmlspecialchars(DEFAULT_OUTPUT_FOLDER); ?>/<name>_<timestamp>.log</code>) for detailed technical information from FFmpeg.</p>

            <?php
            $escapedJsonPayload = escapeshellarg(json_encode($exampleJsonPayload, JSON_UNESCAPED_SLASHES));
            $curlCommand = "curl -X POST " . escapeshellarg($serverUrl) . " \\\n";
            $curlCommand .= "  -H \"Content-Type: application/json\" \\\n";
            $curlCommand .= "  -d $escapedJsonPayload";
            ?>
            <h2>How to Use (cURL Example)</h2>
            <p>Copy the command below, replace the example URLs/values inside the JSON data (`-d` argument), and run it in your terminal.</p>
            <pre id='curl-command'><?php echo htmlspecialchars($curlCommand); ?></pre>
            <button onclick="navigator.clipboard.writeText(document.getElementById('curl-command').innerText.replace(/\\\n/g, '')); alert('cURL command copied (single line format)!');">Copy cURL Command (Single Line)</button>

             <h3>Using with n8n:</h3>
             <ul>
                <li>Use the 'HTTP Request' node.</li>
                <li><strong>Method:</strong> `POST`</li>
                <li><strong>URL:</strong> `<?php echo htmlspecialchars($serverUrl); ?>`</li>
                <li><strong>Send Body:</strong> `On`</li>
                <li><strong>Body Content Type:</strong> `JSON`</li>
                <li><strong>JSON / Raw Parameters:</strong> `On`</li>
                <li><strong>Parameter / JSON:</strong> Enter your JSON payload, using expressions for dynamic data.<br>
                    Example: <code style="font-size: 0.85em;">={{ { "videos": [ $json.url1, $json.url2 ], "audio": $json.newAudioUrl, "name": "n8n_video_" + $now.toMillis() } }}</code>
                </li>
             </ul>

            <h2>Important Notes & Troubleshooting</h2>
            <ul>
                <li><strong>FFmpeg & Permissions:</strong> Crucial! Verify <code class="config-path"><?php echo htmlspecialchars(FFMPEG_PATH); ?></code> is correct & executable by the PHP user. Check `disable_functions` (for `exec`), OS permissions, CageFS, SELinux.</li>
                <li><strong>URLs:</strong> Must be public direct links.</li>
                <li><strong>Resources:</strong> Video processing is slow/intensive. Check PHP limits: `max_execution_time` (<?php echo MAX_EXECUTION_TIME; ?>s), `memory_limit` (<?php echo MEMORY_LIMIT; ?>). Ensure server limits are also high enough.</li>
                <li><strong>Error Logs:</strong> Check the <code>.log</code> file in <code><?php echo htmlspecialchars(DEFAULT_OUTPUT_FOLDER); ?>/</code> on errors, AND check the main server PHP error log. The log file is critical for FFmpeg errors!</li>
                <li><strong>Input Compatibility:</strong> Merging works best with similar video streams. The provided audio file must be a valid format FFmpeg can read.</li>
            </ul>
            <button onclick="location.reload()">Refresh Status & Documentation</button>
        </div>
    </body>
    </html>
    <?php
    exit; 
}


if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    set_time_limit(MAX_EXECUTION_TIME);
    ini_set('memory_limit', MEMORY_LIMIT);
    header('Content-Type: application/json; charset=utf-8');

    $jsonInput = file_get_contents('php://input');
    $data = json_decode($jsonInput, true); 

    $outputFolder = (isset($data['folder']) && is_string($data['folder'])) ? $data['folder'] : DEFAULT_OUTPUT_FOLDER;
    $outputFolder = trim(str_replace( '..', '', preg_replace('/[^a-zA-Z0-9-_.\/]/', '', $outputFolder)), '/');
    if (empty($outputFolder)) $outputFolder = DEFAULT_OUTPUT_FOLDER;

    $customName = (isset($data['name']) && is_string($data['name'])) ? $data['name'] : null;
    $nameBase = $customName ? preg_replace('/[^a-zA-Z0-9-_]/', '', str_replace(' ', '-', $customName)) : 'merged_video';
    $finalBaseNameForLog = $nameBase . '_' . time();

    $outputDirPath = __DIR__ . '/' . $outputFolder;
    $globalLogFilePath = $outputDirPath . '/' . $finalBaseNameForLog . '.log'; 

    if (json_last_error() !== JSON_ERROR_NONE) { handleErrorAndLog("Invalid JSON received: " . json_last_error_msg(), 400, $jsonInput); }

    $videoUrls = $data['videos'] ?? null;
    $audioUrl = $data['audio'] ?? null; // Changed from musicUrl

    if (empty($videoUrls) || !is_array($videoUrls) || count($videoUrls) < 1) { handleErrorAndLog("JSON key 'videos' (array of URLs) is required.", 400, $data); }
    foreach($videoUrls as $index => $url) { if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL)) { handleErrorAndLog("Invalid URL in 'videos' array at index $index: " . htmlspecialchars($url), 400, $data); } }
    if ($audioUrl !== null && (!is_string($audioUrl) || !filter_var($audioUrl, FILTER_VALIDATE_URL))) { handleErrorAndLog("Invalid URL provided for 'audio'.", 400, $data); }
    
    $finalFileName = $nameBase . '_' . time() . '_' . bin2hex(random_bytes(2)) . '.mp4';
    $finalOutputPath = $outputDirPath . '/' . $finalFileName;
    $publicUrlPath = rtrim($basePublicUrl, '/') . '/' . $outputFolder . '/' . $finalFileName;

    writeToLog("Starting processing job (ID: " . $finalBaseNameForLog . "). Output target: " . $finalOutputPath);

    if (!is_dir($outputDirPath)) { if (!@mkdir($outputDirPath, 0775, true) && !is_dir($outputDirPath)) { handleErrorAndLog("Failed to create output directory: $outputDirPath.", 500); }}
    if (!is_writable($outputDirPath)) { handleErrorAndLog("Output directory not writable: $outputDirPath", 500); }
    if (!is_dir(TEMP_DIR)) { if (!@mkdir(TEMP_DIR, 0775, true) && !is_dir(TEMP_DIR)) { handleErrorAndLog("Failed to create temp directory: ".TEMP_DIR, 500); } }
    if (!is_writable(TEMP_DIR)) { handleErrorAndLog("Temp directory not writable: " . TEMP_DIR, 500); }

    $tempFiles = []; $downloadedVideoFiles = []; $downloadedAudioFile = null; // Changed from $downloadedMusicFile
    $ffmpegInputFiles = []; 

    try {
        writeToLog("Starting file downloads...");
        $i = 0;
        foreach ($videoUrls as $url) {
            $tempVideoPath = downloadFileHelper($url, 'vid' . $i++, $tempFiles);
            if($tempVideoPath) { $downloadedVideoFiles[] = $tempVideoPath; }
        }
        $downloadedAudioFile = downloadFileHelper($audioUrl, 'audio', $tempFiles); // Changed type to 'audio'
        writeToLog("File downloads attempted.");
        if (empty($downloadedVideoFiles)) { throw new Exception("No video files were successfully downloaded."); }
    } catch (Exception $e) {
        cleanupTempFiles($tempFiles);
        handleErrorAndLog($e->getMessage(), 400, "Download phase failed.");
    }

    $intermediateFile = null; $error = null; $listFilePath = null; $mergedVideoPath = null;

    try {
        if (count($downloadedVideoFiles) > 1) {
            writeToLog("Preparing to merge " . count($downloadedVideoFiles) . " videos...");
            $listFilePath = TEMP_DIR . '/mylist_' . $finalBaseNameForLog . '.txt';
            $listContent = '';
            foreach ($downloadedVideoFiles as $filePath) {
                 $escapedPathForList = str_replace("'", "'\\''", $filePath); $listContent .= "file '$escapedPathForList'\n";
                 $ffmpegInputFiles[] = $filePath; 
            }
            if (!file_put_contents($listFilePath, $listContent)) { throw new Exception("Failed to write FFmpeg list file: $listFilePath"); }
            $tempFiles[] = $listFilePath; $ffmpegInputFiles[] = $listFilePath; 
            writeToLog("Generated list file: $listFilePath");
            $mergedVideoPath = TEMP_DIR . '/' . generateFilenameBase('merged') . '.mp4';

            writeToLog("Attempting merge (-c copy) to: $mergedVideoPath");
            $ffmpegCommandMerge = "-f concat -safe 0 -i " . escapeshellarg($listFilePath) . " -c copy " . escapeshellarg($mergedVideoPath);
            $error = executeCommand($ffmpegCommandMerge);

            if ($error || !file_exists($mergedVideoPath) || filesize($mergedVideoPath) < 1024) {
                writeToLog("Merge with -c copy failed or produced small file. Attempting re-encode. Error: " . ($error ?? 'Filesize check failed'));
                if(file_exists($mergedVideoPath)) @unlink($mergedVideoPath); 
                $ffmpegCommandMergeReEncode = "-f concat -safe 0 -i " . escapeshellarg($listFilePath) . " -c:v libx264 -preset medium -crf 23 -c:a aac -b:a 128k " . escapeshellarg($mergedVideoPath);
                $error = executeCommand($ffmpegCommandMergeReEncode);
                if ($error || !file_exists($mergedVideoPath) || filesize($mergedVideoPath) < 1024) { throw new Exception($error ?: "Failed to merge videos (re-encoding attempt also failed/small file)."); }
                else { $error = null; writeToLog("Merge via re-encoding succeeded."); }
            } else { writeToLog("Merge via -c copy succeeded."); }
            $intermediateFile = $mergedVideoPath; $tempFiles[] = $mergedVideoPath;
        } elseif (!empty($downloadedVideoFiles)) {
            $intermediateFile = $downloadedVideoFiles[0]; $ffmpegInputFiles[] = $intermediateFile;
            writeToLog("Only one video provided, using directly: $intermediateFile");
        } else { throw new Exception("No valid video files available for processing."); }

        $currentVideoInput = $intermediateFile;
        $finalOutputTarget = $finalOutputPath;

        if ($downloadedAudioFile) {
            writeToLog("Adding new audio track (replacing existing)...");
            $ffmpegCommandAudio = "-i " . escapeshellarg($currentVideoInput);
            $ffmpegInputFiles[] = $downloadedAudioFile; 
            $ffmpegCommandAudio .= " -i " . escapeshellarg($downloadedAudioFile);

            $mapVideo = "-map 0:v:0"; // Video from first input (merged video)
            $mapNewAudio = "-map 1:a:0";  // Audio from second input (new audio file), take first audio stream

            $ffmpegCommandAudio .= " $mapVideo $mapNewAudio -c:v libx264 -preset fast -crf 23 -c:a aac -b:a 160k -shortest " . escapeshellarg($finalOutputTarget);
            
            $error = executeCommand($ffmpegCommandAudio);
            if ($error || !file_exists($finalOutputTarget) || filesize($finalOutputTarget) < 1024) {
                 if(file_exists($finalOutputTarget)) @unlink($finalOutputTarget);
                 throw new Exception($error ?: "Failed to add audio track (output file small/missing).");
            }
            writeToLog("Audio addition completed successfully.");
        } else {
            writeToLog("No new audio track specified. Moving/copying intermediate file to final destination.");
            if ($currentVideoInput === $finalOutputTarget) { // Should not happen if intermediateFile is different from finalOutputPath
                writeToLog("Intermediate file is already at the final destination. This is unexpected unless it's a single video input with no audio processing. Check logic.");
            } elseif (rename($currentVideoInput, $finalOutputTarget)) {
                writeToLog("Rename/move successful.");
                $key = array_search($currentVideoInput, $tempFiles);
                if ($key !== false) {
                    unset($tempFiles[$key]); 
                }
            } else {
                 writeToLog("Rename failed, attempting copy...");
                 if (copy($currentVideoInput, $finalOutputTarget)) {
                     writeToLog("Copy succeeded.");
                     // $currentVideoInput is still in $tempFiles and will be cleaned up if it was a temp merged file
                 } else {
                     $sysError = error_get_last();
                     throw new Exception("Failed to move intermediate video to final destination. Copy error: " . ($sysError['message'] ?? 'Unknown'));
                 }
            }
        }

        writeToLog("Processing successful. Final video URL: " . $publicUrlPath);
        http_response_code(200);
        echo json_encode(["url" => $publicUrlPath]);

    } catch (Exception $e) {
        handleErrorAndLog($e->getMessage(), 500, "Processing phase failed. Check log for FFmpeg details.");
    } finally {
        cleanupTempFiles($tempFiles);
    }
    exit; 
} else {
    handleErrorAndLog("Method not allowed. Use GET for documentation or POST with JSON body.", 405);
}
?>