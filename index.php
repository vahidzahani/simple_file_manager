<?php
// file_manager.php
// Single-file PHP app for listing files and chunked AJAX upload with pause/resume, delete and rename.
// Requirements: PHP 7+, writable folders: ./files and ./uploads_tmp
// Make sure to create them and set permissions: mkdir files uploads_tmp; chmod 0777 files uploads_tmp

// Simple router
$action = $_REQUEST['action'] ?? '';
if ($action === 'upload_chunk') {
    handle_upload_chunk();
} elseif ($action === 'upload_complete') {
    handle_upload_complete();
} elseif ($action === 'delete') {
    handle_delete();
} elseif ($action === 'rename') {
    handle_rename();
} else {
    render_page();
}

exit;

function json_response($data)
{
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function handle_upload_chunk()
{
    // Expect POST with: uploadId, chunkIndex (0-based), totalChunks, name, size
    // and file chunk in 'file' field
    $uploadId = $_POST['uploadId'] ?? null;
    $chunkIndex = isset($_POST['chunkIndex']) ? intval($_POST['chunkIndex']) : null;
    $totalChunks = isset($_POST['totalChunks']) ? intval($_POST['totalChunks']) : null;
    $name = $_POST['name'] ?? 'upload.bin';
    $size = isset($_POST['size']) ? intval($_POST['size']) : 0;

    if (!$uploadId || !is_numeric($chunkIndex) || !$totalChunks) {
        json_response(['ok' => false, 'error' => 'پارامترهای ارسالی ناقص است']);
    }

    if (!isset($_FILES['file'])) {
        json_response(['ok' => false, 'error' => 'هیچ داده‌ای ارسال نشده است']);
    }

    $tmpDir = __DIR__ . '/uploads_tmp';
    if (!is_dir($tmpDir)) mkdir($tmpDir, 0777, true);

    $chunkPath = $tmpDir . '/' . $uploadId . '.part' . $chunkIndex;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $chunkPath)) {
        json_response(['ok' => false, 'error' => 'ذخیره چانک انجام نشد']);
    }

    // Optionally: cleanup old partial uploads (not implemented here)

    json_response(['ok' => true]);
}

function handle_upload_complete()
{
    // Expect POST: uploadId, name, totalChunks
    $uploadId = $_POST['uploadId'] ?? null;
    $name = $_POST['name'] ?? 'file.bin';
    $totalChunks = isset($_POST['totalChunks']) ? intval($_POST['totalChunks']) : 0;

    if (!$uploadId || !$totalChunks) json_response(['ok' => false, 'error' => 'پارامترها ناقص‌اند']);

    $tmpDir = __DIR__ . '/uploads_tmp';
    $finalDir = __DIR__ . '/files';
    if (!is_dir($finalDir)) mkdir($finalDir, 0777, true);

    // sanitize filename
    $name = basename($name);
    $finalPath = $finalDir . '/' . $name;

    // if file exists, add suffix
    $base = pathinfo($name, PATHINFO_FILENAME);
    $ext = pathinfo($name, PATHINFO_EXTENSION);
    $counter = 1;
    while (file_exists($finalPath)) {
        $finalPath = $finalDir . '/' . $base . '_' . $counter . ($ext ? '.' . $ext : '');
        $counter++;
    }

    $out = fopen($finalPath, 'wb');
    if (!$out) json_response(['ok' => false, 'error' => 'نشد فایل نهایی ساخته شود']);

    for ($i = 0; $i < $totalChunks; $i++) {
        $chunkPath = $tmpDir . '/' . $uploadId . '.part' . $i;
        if (!file_exists($chunkPath)) {
            fclose($out);
            json_response(['ok' => false, 'error' => "چانک $i وجود ندارد"]);
        }
        $in = fopen($chunkPath, 'rb');
        stream_copy_to_stream($in, $out);
        fclose($in);
        // remove chunk
        unlink($chunkPath);
    }

    fclose($out);

    json_response(['ok' => true, 'path' => 'files/' . basename($finalPath)]);
}

function handle_delete()
{
    $file = $_POST['file'] ?? null;
    if (!$file) json_response(['ok' => false, 'error' => 'پارامتر فایل نیست']);
    $path = __DIR__ . '/files/' . basename($file);
    if (!file_exists($path)) json_response(['ok' => false, 'error' => 'فایل پیدا نشد']);
    if (!unlink($path)) json_response(['ok' => false, 'error' => 'حذف نشد']);
    json_response(['ok' => true]);
}

function handle_rename()
{
    $file = $_POST['file'] ?? null;
    $new = $_POST['new'] ?? null;
    if (!$file || !$new) json_response(['ok' => false, 'error' => 'پارامترها ناقص هستند']);
    $oldPath = __DIR__ . '/files/' . basename($file);
    $newPath = __DIR__ . '/files/' . basename($new);
    if (!file_exists($oldPath)) json_response(['ok' => false, 'error' => 'فایل قدیمی وجود ندارد']);
    if (file_exists($newPath)) json_response(['ok' => false, 'error' => 'فایل جدید از قبل وجود دارد']);
    if (!rename($oldPath, $newPath)) json_response(['ok' => false, 'error' => 'تغییر نام انجام نشد']);
    json_response(['ok' => true, 'new' => basename($newPath)]);
}

function list_files_html()
{
    $dir = __DIR__ . '/files';
    if (!is_dir($dir)) mkdir($dir, 0777, true);
    $files = array_diff(scandir($dir), ['.', '..']);
    $rows = '';
    foreach ($files as $f) {
        $size = filesize($dir . '/' . $f);
        $rows .= "<tr data-file='" . htmlspecialchars($f, ENT_QUOTES) . "'>\n";
        $rows .= "<td>" . htmlspecialchars($f) . "</td>\n";
        $rows .= "<td>" . format_bytes($size) . "</td>\n";
        $rows .= "<td>\n<button class='btn btn-xs rename-btn'>تغییر نام</button> \n<button class='btn btn-xs delete-btn'>حذف</button>\n</td>\n";
        $rows .= "</tr>\n";
    }
    return $rows;
}

function format_bytes($b)
{
    if ($b >= 1073741824) return round($b / 1073741824, 2) . ' GB';
    if ($b >= 1048576) return round($b / 1048576, 2) . ' MB';
    if ($b >= 1024) return round($b / 1024, 2) . ' KB';
    return $b . ' B';
}

function render_page()
{
?>
    <!doctype html>
    <html lang="fa">

    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>مدیریت فایل ساده</title>
        <style>
            :root {
                --bg: #0f172a;
                --card: #0b1220;
                --muted: #94a3b8;
                --accent: #0ea5a7
            }

            body {
                font-family: Vazir, Tahoma, Arial;
                background: linear-gradient(180deg, #071024 0%, #081426 100%);
                color: #e6eef6;
                margin: 0;
                padding: 24px
            }

            .container {
                max-width: 1000px;
                margin: 0 auto
            }

            .card {
                background: rgba(255, 255, 255, 0.03);
                padding: 20px;
                border-radius: 12px;
                box-shadow: 0 6px 24px rgba(2, 6, 23, 0.6)
            }

            .header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                margin-bottom: 12px
            }

            .h1 {
                font-size: 20px
            }

            .files-table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 12px
            }

            .files-table th,
            .files-table td {
                padding: 8px 10px;
                text-align: left;
                border-bottom: 1px solid rgba(255, 255, 255, 0.03)
            }

            .btn {
                background: var(--accent);
                color: #022;
                padding: 8px 12px;
                border-radius: 8px;
                border: none;
                cursor: pointer
            }

            .btn.outline {
                background: transparent;
                border: 1px solid rgba(255, 255, 255, 0.06);
                color: var(--muted)
            }

            .small {
                font-size: 13px;
                padding: 6px 8px
            }

            .upload-area {
                border: 2px dashed rgba(255, 255, 255, 0.06);
                padding: 18px;
                border-radius: 10px;
                text-align: center;
                margin-bottom: 12px
            }

            .progress {
                height: 10px;
                background: rgba(255, 255, 255, 0.03);
                border-radius: 8px;
                overflow: hidden
            }

            .progress>i {
                display: block;
                height: 100%;
                width: 0%;
                background: linear-gradient(90deg, #34d399, #06b6d4)
            }

            .controls {
                display: flex;
                gap: 8px;
                flex-wrap: wrap
            }

            .row {
                display: flex;
                gap: 12px;
                align-items: center
            }

            .input {
                padding: 8px;
                border-radius: 8px;
                border: 1px solid rgba(255, 255, 255, 0.04);
                background: transparent;
                color: inherit
            }

            .rename-input {
                width: 200px
            }

            .note {
                color: var(--muted);
                font-size: 13px;
                margin-top: 8px
            }
        </style>
    </head>

    <body>





        <?php
        ?>

        <div class="container">
            <div class="card">
                <div class="header">
                    <div class="h1">مدیریت فایل — پوشه <code>files/</code></div>
                    <div class="note">آپلود بزرگ (قابل توقف/ادامه)، حذف و تغییر نام</div>
                </div>

                <div class="upload-area" id="uploadArea">
                    <div style="margin-bottom:8px">فایل را بکشید یا انتخاب کنید</div>
                    <input id="fileInput" type="file" style="display:none">
                    <div class="controls">
                        <button class="btn" id="pickBtn">انتخاب فایل</button>
                        <button class="btn outline" id="pauseBtn" style="display:none">مکث</button>
                        <button class="btn outline" id="resumeBtn" style="display:none">ادامه</button>
                    </div>
                    <div class="note">حجم تا 1 گیگ توصیه‌شده. تقسیم به تکه‌ها برای upload قابل ادامه.</div>
                    <div style="margin-top:12px">
                        <div class="progress"><i id="progressBar"></i></div>
                        <div style="display:flex;justify-content:space-between;margin-top:6px">
                            <div id="statusText" class="note">آماده</div>
                            <div id="speedText" class="note"></div>
                        </div>
                    </div>
                </div>

                <table class="files-table" id="filesTable">
                    <thead>
                        <tr>
                            <th>نام فایل</th>
                            <th>حجم</th>
                            <th>عملیات</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php echo list_files_html(); ?>
                    </tbody>
                </table>
            </div>
        </div>

        <script>
            // Chunked uploader with pause/resume
            const CHUNK_SIZE = 1024 * 1024; // 1 MB per chunk (adjustable)
            let file = null;
            let uploadId = null;
            let totalChunks = 0;
            let currentChunk = 0;
            let paused = false;
            let xhr = null;
            let startTime = 0;
            let uploadedBytes = 0;

            const pickBtn = document.getElementById('pickBtn');
            const fileInput = document.getElementById('fileInput');
            const pauseBtn = document.getElementById('pauseBtn');
            const resumeBtn = document.getElementById('resumeBtn');
            const progressBar = document.getElementById('progressBar');
            const statusText = document.getElementById('statusText');
            const speedText = document.getElementById('speedText');
            const uploadArea = document.getElementById('uploadArea');

            pickBtn.onclick = () => fileInput.click();
            fileInput.onchange = (e) => {
                if (e.target.files[0]) startUpload(e.target.files[0]);
            };

            uploadArea.addEventListener('dragover', (e) => {
                e.preventDefault();
                uploadArea.style.borderColor = '#06b6d4';
            });
            uploadArea.addEventListener('dragleave', (e) => {
                uploadArea.style.borderColor = '';
            });
            uploadArea.addEventListener('drop', (e) => {
                e.preventDefault();
                uploadArea.style.borderColor = '';
                if (e.dataTransfer.files[0]) startUpload(e.dataTransfer.files[0]);
            });

            pauseBtn.onclick = () => {
                paused = true;
                pauseBtn.style.display = 'none';
                resumeBtn.style.display = '';
                statusText.textContent = 'مکث شده';
                if (xhr) xhr.abort();
            };
            resumeBtn.onclick = () => {
                paused = false;
                resumeBtn.style.display = 'none';
                pauseBtn.style.display = '';
                statusText.textContent = 'ادامه...';
                uploadNextChunk();
            };

            function startUpload(f) {
                file = f;
                // compute uploadId deterministically
                uploadId = md5(file.name + '|' + file.size + '|' + file.lastModified);
                totalChunks = Math.ceil(file.size / CHUNK_SIZE);
                currentChunk = 0;
                uploadedBytes = 0;
                paused = false;
                startTime = Date.now();
                pauseBtn.style.display = '';
                resumeBtn.style.display = 'none';
                statusText.textContent = 'در حال آماده‌سازی...';
                progressBar.style.width = '0%';

                // Check which chunks exist on server? (not implemented) — we start from 0. Could enhance by querying.
                uploadNextChunk();
            }

            function uploadNextChunk() {
                if (!file) return;
                if (paused) return;
                if (currentChunk >= totalChunks) {
                    // finalize
                    fetch('', {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/x-www-form-urlencoded'
                            },
                            body: new URLSearchParams({
                                action: 'upload_complete',
                                uploadId: uploadId,
                                name: file.name,
                                totalChunks: totalChunks
                            })
                        })
                        .then(r => r.json()).then(j => {
                            if (j.ok) {
                                statusText.textContent = 'آپلود کامل شد';
                                progressBar.style.width = '100%';
                                refreshFileList();
                            } else {
                                statusText.textContent = 'خطا: ' + (j.error || 'نامشخص');
                            }
                        }).catch(e => statusText.textContent = 'خطا در نهایی‌سازی');
                    return;
                }

                const start = currentChunk * CHUNK_SIZE;
                const end = Math.min(start + CHUNK_SIZE, file.size);
                const blob = file.slice(start, end);
                const form = new FormData();
                form.append('action', 'upload_chunk');
                form.append('uploadId', uploadId);
                form.append('chunkIndex', currentChunk);
                form.append('totalChunks', totalChunks);
                form.append('name', file.name);
                form.append('size', file.size);
                form.append('file', blob, file.name);

                xhr = new XMLHttpRequest();
                xhr.open('POST', '');
                xhr.onload = function() {
                    if (xhr.status === 200) {
                        try {
                            const res = JSON.parse(xhr.responseText);
                            if (!res.ok) {
                                statusText.textContent = 'خطا: ' + (res.error || 'نامشخص');
                                return;
                            }
                        } catch (e) {
                            statusText.textContent = 'پاسخ نامعتبر از سرور';
                            return;
                        }
                        uploadedBytes += (end - start);
                        const percent = Math.floor((uploadedBytes / file.size) * 100);
                        progressBar.style.width = percent + '%';
                        const elapsed = (Date.now() - startTime) / 1000; // seconds
                        const speed = uploadedBytes / Math.max(1, elapsed); // bytes/sec
                        speedText.textContent = humanSize(speed) + '/s';
                        statusText.textContent = `در حال آپلود... ${percent}% (چانک ${currentChunk+1}/${totalChunks})`;
                        currentChunk++;
                        uploadNextChunk();
                    } else {
                        statusText.textContent = 'آی‌اچ‌تی‌تی‌پی خطا: ' + xhr.status;
                    }
                };
                xhr.onerror = function() {
                    statusText.textContent = 'خطا در ارسال';
                };
                xhr.send(form);
            }

            function refreshFileList() {
                fetch(location.href).then(r => r.text()).then(html => {
                    // parse new tbody
                    const tmp = document.createElement('div');
                    tmp.innerHTML = html;
                    const newTbody = tmp.querySelector('#filesTable tbody');
                    if (newTbody) document.querySelector('#filesTable tbody').innerHTML = newTbody.innerHTML;
                    attachFileButtons();
                });
            }

            function attachFileButtons() {
                document.querySelectorAll('.delete-btn').forEach(btn => {
                    btn.onclick = () => {
                        const tr = btn.closest('tr');
                        const file = tr.getAttribute('data-file');
                        if (!confirm('آیا مطمئنید می‌خواهید حذف شود؟')) return;
                        fetch('', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded'
                                },
                                body: new URLSearchParams({
                                    action: 'delete',
                                    file: file
                                })
                            })
                            .then(r => r.json()).then(j => {
                                if (j.ok) {
                                    tr.remove();
                                } else alert('خطا: ' + (j.error || 'نامشخص'));
                            });
                    };
                });
                document.querySelectorAll('.rename-btn').forEach(btn => {
                    btn.onclick = () => {
                        const tr = btn.closest('tr');
                        const file = tr.getAttribute('data-file');
                        const newName = prompt('نام جدید را وارد کنید', file);
                        if (!newName) return;
                        fetch('', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/x-www-form-urlencoded'
                                },
                                body: new URLSearchParams({
                                    action: 'rename',
                                    file: file,
                                    new: newName
                                })
                            })
                            .then(r => r.json()).then(j => {
                                if (j.ok) {
                                    tr.querySelector('td:first-child').textContent = j.new;
                                    tr.setAttribute('data-file', j.new);
                                } else alert('خطا: ' + (j.error || 'نامشخص'));
                            });
                    };
                });
            }

            attachFileButtons();

            function humanSize(bytes) {
                if (bytes > 1024 * 1024) return (bytes / 1024 / 1024).toFixed(2) + ' MB';
                if (bytes > 1024) return (bytes / 1024).toFixed(2) + ' KB';
                return bytes + ' B';
            }

            // Lightweight MD5 implementation (for uploadId) — small inline implementation
            function md5(str) {
                // Using a tiny md5 implementation (adapted). For production, use a tested lib.
                function rotateLeft(lValue, iShiftBits) {
                    return (lValue << iShiftBits) | (lValue >>> (32 - iShiftBits));
                }

                function addUnsigned(lX, lY) {
                    var lX4, lY4, lX8, lY8, lResult;
                    lX8 = (lX & 0x80000000);
                    lY8 = (lY & 0x80000000);
                    lX4 = (lX & 0x40000000);
                    lY4 = (lY & 0x40000000);
                    lResult = (lX & 0x3FFFFFFF) + (lY & 0x3FFFFFFF);
                    if (lX4 & lY4) return (lResult ^ 0x80000000 ^ lX8 ^ lY8);
                    if (lX4 | lY4) {
                        if (lResult & 0x40000000) return (lResult ^ 0xC0000000 ^ lX8 ^ lY8);
                        else return (lResult ^ 0x40000000 ^ lX8 ^ lY8);
                    } else return (lResult ^ lX8 ^ lY8);
                }

                function F(x, y, z) {
                    return (x & y) | ((~x) & z);
                }

                function G(x, y, z) {
                    return (x & z) | (y & (~z));
                }

                function H(x, y, z) {
                    return (x ^ y ^ z);
                }

                function I(x, y, z) {
                    return (y ^ (x | (~z)));
                }

                function FF(a, b, c, d, x, s, ac) {
                    a = addUnsigned(a, addUnsigned(addUnsigned(F(b, c, d), x), ac));
                    return addUnsigned(rotateLeft(a, s), b);
                }

                function GG(a, b, c, d, x, s, ac) {
                    a = addUnsigned(a, addUnsigned(addUnsigned(G(b, c, d), x), ac));
                    return addUnsigned(rotateLeft(a, s), b);
                }

                function HH(a, b, c, d, x, s, ac) {
                    a = addUnsigned(a, addUnsigned(addUnsigned(H(b, c, d), x), ac));
                    return addUnsigned(rotateLeft(a, s), b);
                }

                function II(a, b, c, d, x, s, ac) {
                    a = addUnsigned(a, addUnsigned(addUnsigned(I(b, c, d), x), ac));
                    return addUnsigned(rotateLeft(a, s), b);
                }

                function convertToWordArray(str) {
                    var lWordCount;
                    var lMessageLength = str.length;
                    var lNumberOfWords_temp1 = lMessageLength + 8;
                    var lNumberOfWords_temp2 = (lNumberOfWords_temp1 - (lNumberOfWords_temp1 % 64)) / 64;
                    var lNumberOfWords = (lNumberOfWords_temp2 + 1) * 16;
                    var lWordArray = Array(lNumberOfWords - 1);
                    var lBytePosition = 0;
                    var lByteCount = 0;
                    while (lByteCount < lMessageLength) {
                        var lWordCount = (lByteCount - (lByteCount % 4)) / 4;
                        var lBytePosition = (lByteCount % 4) * 8;
                        lWordArray[lWordCount] = (lWordArray[lWordCount] | (str.charCodeAt(lByteCount) << lBytePosition));
                        lByteCount++;
                    }
                    var lWordCount = (lByteCount - (lByteCount % 4)) / 4;
                    var lBytePosition = (lByteCount % 4) * 8;
                    lWordArray[lWordCount] = lWordArray[lWordCount] | (0x80 << lBytePosition);
                    lWordArray[lNumberOfWords - 2] = lMessageLength << 3;
                    lWordArray[lNumberOfWords - 1] = lMessageLength >>> 29;
                    return lWordArray;
                }

                function wordToHex(lValue) {
                    var wordToHexValue = "",
                        wordToHexValue_temp = "",
                        lByte, lCount;
                    for (lCount = 0; lCount <= 3; lCount++) {
                        lByte = (lValue >>> (lCount * 8)) & 255;
                        wordToHexValue_temp = "0" + lByte.toString(16);
                        wordToHexValue = wordToHexValue + wordToHexValue_temp.substr(wordToHexValue_temp.length - 2, 2);
                    }
                    return wordToHexValue;
                }
                var x = Array();
                var k, AA, BB, CC, DD, a, b, c, d;
                var S11 = 7,
                    S12 = 12,
                    S13 = 17,
                    S14 = 22;
                var S21 = 5,
                    S22 = 9,
                    S23 = 14,
                    S24 = 20;
                var S31 = 4,
                    S32 = 11,
                    S33 = 16,
                    S34 = 23;
                var S41 = 6,
                    S42 = 10,
                    S43 = 15,
                    S44 = 21;
                x = convertToWordArray(unescape(encodeURIComponent(str)));
                a = 0x67452301;
                b = 0xEFCDAB89;
                c = 0x98BADCFE;
                d = 0x10325476;
                for (k = 0; k < x.length; k += 16) {
                    AA = a;
                    BB = b;
                    CC = c;
                    DD = d;
                    a = FF(a, b, c, d, x[k + 0], S11, 0xD76AA478);
                    d = FF(d, a, b, c, x[k + 1], S12, 0xE8C7B756);
                    c = FF(c, d, a, b, x[k + 2], S13, 0x242070DB);
                    b = FF(b, c, d, a, x[k + 3], S14, 0xC1BDCEEE);
                    a = FF(a, b, c, d, x[k + 4], S11, 0xF57C0FAF);
                    d = FF(d, a, b, c, x[k + 5], S12, 0x4787C62A);
                    c = FF(c, d, a, b, x[k + 6], S13, 0xA8304613);
                    b = FF(b, c, d, a, x[k + 7], S14, 0xFD469501);
                    a = FF(a, b, c, d, x[k + 8], S11, 0x698098D8);
                    d = FF(d, a, b, c, x[k + 9], S12, 0x8B44F7AF);
                    c = FF(c, d, a, b, x[k + 10], S13, 0xFFFF5BB1);
                    b = FF(b, c, d, a, x[k + 11], S14, 0x895CD7BE);
                    a = FF(a, b, c, d, x[k + 12], S11, 0x6B901122);
                    d = FF(d, a, b, c, x[k + 13], S12, 0xFD987193);
                    c = FF(c, d, a, b, x[k + 14], S13, 0xA679438E);
                    b = FF(b, c, d, a, x[k + 15], S14, 0x49B40821);
                    a = GG(a, b, c, d, x[k + 1], S21, 0xF61E2562);
                    d = GG(d, a, b, c, x[k + 6], S22, 0xC040B340);
                    c = GG(c, d, a, b, x[k + 11], S23, 0x265E5A51);
                    b = GG(b, c, d, a, x[k + 0], S24, 0xE9B6C7AA);
                    a = GG(a, b, c, d, x[k + 5], S21, 0xD62F105D);
                    d = GG(d, a, b, c, x[k + 10], S22, 0x2441453);
                    c = GG(c, d, a, b, x[k + 15], S23, 0xD8A1E681);
                    b = GG(b, c, d, a, x[k + 4], S24, 0xE7D3FBC8);
                    a = GG(a, b, c, d, x[k + 9], S21, 0x21E1CDE6);
                    d = GG(d, a, b, c, x[k + 14], S22, 0xC33707D6);
                    c = GG(c, d, a, b, x[k + 3], S23, 0xF4D50D87);
                    b = GG(b, c, d, a, x[k + 8], S24, 0x455A14ED);
                    a = GG(a, b, c, d, x[k + 13], S21, 0xA9E3E905);
                    d = GG(d, a, b, c, x[k + 2], S22, 0xFCEFA3F8);
                    c = GG(c, d, a, b, x[k + 7], S23, 0x676F02D9);
                    b = GG(b, c, d, a, x[k + 12], S24, 0x8D2A4C8A);
                    a = HH(a, b, c, d, x[k + 5], S31, 0xFFFA3942);
                    d = HH(d, a, b, c, x[k + 8], S32, 0x8771F681);
                    c = HH(c, d, a, b, x[k + 11], S33, 0x6D9D6122);
                    b = HH(b, c, d, a, x[k + 14], S34, 0xFDE5380C);
                    a = HH(a, b, c, d, x[k + 1], S31, 0xA4BEEA44);
                    d = HH(d, a, b, c, x[k + 4], S32, 0x4BDECFA9);
                    c = HH(c, d, a, b, x[k + 7], S33, 0xF6BB4B60);
                    b = HH(b, c, d, a, x[k + 10], S34, 0xBEBFBC70);
                    a = HH(a, b, c, d, x[k + 13], S31, 0x289B7EC6);
                    d = HH(d, a, b, c, x[k + 0], S32, 0xEAA127FA);
                    c = HH(c, d, a, b, x[k + 3], S33, 0xD4EF3085);
                    b = HH(b, c, d, a, x[k + 6], S34, 0x4881D05);
                    a = HH(a, b, c, d, x[k + 9], S31, 0xD9D4D039);
                    d = HH(d, a, b, c, x[k + 12], S32, 0xE6DB99E5);
                    c = HH(c, d, a, b, x[k + 15], S33, 0x1FA27CF8);
                    b = HH(b, c, d, a, x[k + 2], S34, 0xC4AC5665);
                    a = II(a, b, c, d, x[k + 0], S41, 0xF4292244);
                    d = II(d, a, b, c, x[k + 7], S42, 0x432AFF97);
                    c = II(c, d, a, b, x[k + 14], S43, 0xAB9423A7);
                    b = II(b, c, d, a, x[k + 5], S44, 0xFC93A039);
                    a = II(a, b, c, d, x[k + 12], S41, 0x655B59C3);
                    d = II(d, a, b, c, x[k + 3], S42, 0x8F0CCC92);
                    c = II(c, d, a, b, x[k + 10], S43, 0xFFEFF47D);
                    b = II(b, c, d, a, x[k + 1], S44, 0x85845DD1);
                    a = II(a, b, c, d, x[k + 8], S41, 0x6FA87E4F);
                    d = II(d, a, b, c, x[k + 15], S42, 0xFE2CE6E0);
                    c = II(c, d, a, b, x[k + 6], S43, 0xA3014314);
                    b = II(b, c, d, a, x[k + 13], S44, 0x4E0811A1);
                    a = II(a, b, c, d, x[k + 4], S41, 0xF7537E82);
                    d = II(d, a, b, c, x[k + 11], S42, 0xBD3AF235);
                    c = II(c, d, a, b, x[k + 2], S43, 0x2AD7D2BB);
                    b = II(b, c, d, a, x[k + 9], S44, 0xEB86D391);
                    a = addUnsigned(a, AA);
                    b = addUnsigned(b, BB);
                    c = addUnsigned(c, CC);
                    d = addUnsigned(d, DD);
                }
                var temp = wordToHex(a) + wordToHex(b) + wordToHex(c) + wordToHex(d);
                return temp.toLowerCase();
            }
        </script>
    </body>

    </html>
<?php
}
