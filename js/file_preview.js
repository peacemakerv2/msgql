// file_preview.js - версия 6.5 с исправленным контрастным Excel, поддержкой .doc и PDF
(function() {
'use strict';

const MAX_ZOOM = 10;
const MIN_ZOOM = 0.25;
const ZOOM_STEP = 0.1;

let modal = null;
let currentBlobUrl = null;
let currentImgElement = null;

function safeLog() {
    if (typeof logDebug === 'function') {
        logDebug.apply(null, arguments);
    } else if (console && console.log) {
        console.log.apply(console, arguments);
    }
}

function safeError() {
    if (typeof logError === 'function') {
        logError.apply(null, arguments);
    } else if (console && console.error) {
        console.error.apply(console, arguments);
    }
}

const modalStyles = document.createElement('style');
modalStyles.textContent = `
    @media (max-width: 768px) {
        #filePreviewModal > div { width: 95% !important; height: 90% !important; border-radius: 12px !important; }
        #filePreviewModal .preview-btn { padding: 6px 12px !important; font-size: 12px !important; }
        #previewFileName { font-size: 13px !important; }
        #previewFileMeta { font-size: 10px !important; }
        #zoomControls { bottom: 10px !important; right: 10px !important; padding: 4px 8px !important; }
        .zoom-btn { width: 44px !important; height: 44px !important; font-size: 20px !important; border-radius: 50% !important; display: inline-flex !important; align-items: center !important; justify-content: center !important; }
        #zoomLevel { font-size: 12px !important; padding: 0 8px !important; min-width: 50px !important; text-align: center !important; }
    }
    @media (max-width: 480px) {
        #filePreviewModal > div { width: 98% !important; height: 95% !important; }
        .preview-loading { font-size: 12px !important; }
        .zoom-btn { width: 40px !important; height: 40px !important; font-size: 18px !important; }
        #filePreviewModal iframe { width: 100% !important; height: 85% !important; }
    }
`;
document.head.appendChild(modalStyles);

function createModal() {
    if (modal) return modal;
    
    const modalHTML = `
        <div id="filePreviewModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.85); z-index: 10000; align-items: center; justify-content: center;">
            <div style="background: #0b1020; border-radius: 20px; width: 95%; height: 85%; max-width: 1600px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 20px 40px rgba(0,0,0,0.4);">
                <div style="padding: 16px 20px; border-bottom: 1px solid rgba(255,255,255,0.1); display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;">
                    <div style="flex: 1; min-width: 0;">
                        <div id="previewFileName" style="font-weight: 600; font-size: 16px; margin-bottom: 4px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"></div>
                        <div id="previewFileMeta" style="font-size: 12px; color: rgba(233,238,252,0.6);"></div>
                    </div>
                    <div style="display: flex; gap: 12px;">
                        <button id="previewDownloadBtn" class="preview-btn" style="background: #4f7cff; border: none; padding: 8px 16px; border-radius: 8px; color: white; cursor: pointer; font-size: 14px;">📥 Скачать</button>
                        <button id="previewCloseBtn" class="preview-btn" style="background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.15); padding: 8px 16px; border-radius: 8px; color: white; cursor: pointer; font-size: 14px;">✕ Закрыть</button>
                    </div>
                </div>
                <div id="previewContent" style="flex: 1; overflow: hidden; display: flex; align-items: center; justify-content: center; background: #050810; position: relative;">
                    <div class="preview-loading" style="text-align: center; color: rgba(255,255,255,0.5);">Загрузка...</div>
                </div>
                <div id="zoomControls" style="display: none; position: absolute; bottom: 20px; right: 30px; gap: 8px; background: rgba(11,16,32,0.9); backdrop-filter: blur(10px); padding: 8px 12px; border-radius: 40px; border: 1px solid rgba(255,255,255,0.1); z-index: 10001;">
                    <button id="zoomOutBtn" class="zoom-btn" style="width: 36px; height: 36px; border-radius: 50%; background: rgba(255,255,255,0.1); border: none; color: white; font-size: 18px; cursor: pointer;">−</button>
                    <span id="zoomLevel" style="font-size: 13px; padding: 0 12px; color: rgba(233,238,252,0.8); min-width: 45px; text-align: center;">100%</span>
                    <button id="zoomInBtn" class="zoom-btn" style="width: 36px; height: 36px; border-radius: 50%; background: rgba(255,255,255,0.1); border: none; color: white; font-size: 18px; cursor: pointer;">+</button>
                    <button id="resetZoomBtn" class="zoom-btn" style="width: 36px; height: 36px; border-radius: 50%; background: rgba(255,255,255,0.1); border: none; color: white; font-size: 14px; cursor: pointer;">✓</button>
                    <button id="rotateBtn" class="zoom-btn" style="width: 36px; height: 36px; border-radius: 50%; background: rgba(255,255,255,0.1); border: none; color: white; font-size: 16px; cursor: pointer;">⟳</button>
                </div>
            </div>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', modalHTML);
    modal = document.getElementById('filePreviewModal');
    
    const closeBtn = document.getElementById('previewCloseBtn');
    if (closeBtn) closeBtn.onclick = function(e) { e.preventDefault(); closeModal(); };
    
    modal.addEventListener('click', function(e) { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', function(e) { if (e.key === 'Escape' && modal.style.display === 'flex') closeModal(); });
    
    return modal;
}

let currentZoom = 1, panX = 0, panY = 0, currentRotation = 0, isDragging = false, startX, startY, currentContainer = null;

function updateImageTransform(img) {
    if (!img) return;
    img.style.transform = `translate(${panX}px, ${panY}px) scale(${currentZoom})`;
    const zoomLevelSpan = document.getElementById('zoomLevel');
    if (zoomLevelSpan) zoomLevelSpan.innerText = Math.round(currentZoom * 100) + '%';
}

function setupImageZoom(img, container) {
    currentImgElement = img;
    currentContainer = container;
    currentZoom = 1;
    panX = 0;
    panY = 0;
    currentRotation = 0;  // ========== ДОБАВЛЕНО для поворота ==========
    
    const zoomInBtn = document.getElementById('zoomInBtn');
    const zoomOutBtn = document.getElementById('zoomOutBtn');
    const resetZoomBtn = document.getElementById('resetZoomBtn');
    const rotateBtn = document.getElementById('rotateBtn');  // ========== ДОБАВЛЕНО ==========
    const zoomControls = document.getElementById('zoomControls');
    
    if (zoomControls) zoomControls.style.display = 'flex';
    
    // ========== ФУНКЦИЯ ОБНОВЛЕНИЯ ТРАНСФОРМАЦИИ (с учётом поворота) ==========
    function updateImageTransformWithRotation() {
        if (!img) return;
        img.style.transform = `translate(${panX}px, ${panY}px) scale(${currentZoom}) rotate(${currentRotation}deg)`;
        const zoomLevelSpan = document.getElementById('zoomLevel');
        if (zoomLevelSpan) zoomLevelSpan.innerText = Math.round(currentZoom * 100) + '%';
    }
    
    function zoomIn() {
        if (currentZoom < MAX_ZOOM) {
            currentZoom = Math.min(MAX_ZOOM, currentZoom + ZOOM_STEP);
            updateImageTransformWithRotation();
        }
    }
    
    function zoomOut() {
        if (currentZoom > MIN_ZOOM) {
            currentZoom = Math.max(MIN_ZOOM, currentZoom - ZOOM_STEP);
            updateImageTransformWithRotation();
        }
    }
    
    function resetZoom() {
        currentZoom = 1;
        panX = 0;
        panY = 0;
        currentRotation = 0;  // ========== СБРАСЫВАЕМ ПОВОРОТ ==========
        updateImageTransformWithRotation();
    }
    
    // ========== ФУНКЦИЯ ПОВОРОТА ПО ЧАСОВОЙ СТРЕЛКЕ ==========
    function rotateImage() {
        currentRotation = (currentRotation + 90) % 360;
        updateImageTransformWithRotation();
        safeLog('[file_preview] Image rotated to:', currentRotation + 'deg');
    }
    
    if (zoomInBtn) zoomInBtn.onclick = zoomIn;
    if (zoomOutBtn) zoomOutBtn.onclick = zoomOut;
    if (resetZoomBtn) resetZoomBtn.onclick = resetZoom;
    if (rotateBtn) rotateBtn.onclick = rotateImage;  // ========== ДОБАВЛЕНО ==========
    
    container.style.cursor = 'grab';
    
    function onMouseDown(e) {
        if (currentZoom > 1) {
            isDragging = true;
            startX = e.clientX - panX;
            startY = e.clientY - panY;
            container.style.cursor = 'grabbing';
            e.preventDefault();
        }
    }
    
    function onMouseMove(e) {
        if (isDragging) {
            panX = e.clientX - startX;
            panY = e.clientY - startY;
            updateImageTransformWithRotation();
        }
    }
    
    function onMouseUp() {
        isDragging = false;
        container.style.cursor = 'grab';
    }
    
    container.removeEventListener('mousedown', onMouseDown);
    container.removeEventListener('mousemove', onMouseMove);
    window.removeEventListener('mouseup', onMouseUp);
    container.addEventListener('mousedown', onMouseDown);
    container.addEventListener('mousemove', onMouseMove);
    window.addEventListener('mouseup', onMouseUp);
    
    function onWheel(e) {
        e.preventDefault();
        e.stopPropagation();
        const delta = e.deltaY > 0 ? -1 : 1;
        let newZoom = currentZoom + (delta * ZOOM_STEP);
        newZoom = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, newZoom));
        if (newZoom !== currentZoom) {
            const rect = img.getBoundingClientRect();
            const mouseX = e.clientX - rect.left;
            const mouseY = e.clientY - rect.top;
            const percentX = mouseX / rect.width;
            const percentY = mouseY / rect.height;
            const oldZoom = currentZoom;
            currentZoom = newZoom;
            if (oldZoom !== currentZoom && currentZoom > 1) {
                const scaleChange = currentZoom / oldZoom;
                panX = panX * scaleChange + (percentX - 0.5) * rect.width * (scaleChange - 1);
                panY = panY * scaleChange + (percentY - 0.5) * rect.height * (scaleChange - 1);
            }
            updateImageTransformWithRotation();
        }
    }
    
    container.addEventListener('wheel', onWheel, { passive: false });
    
    let touchStartX = 0, touchStartY = 0, initialDistance = 0, initialZoom = 1;
    
    function getTouchDistance(touches) {
        if (touches.length < 2) return 0;
        const dx = touches[0].clientX - touches[1].clientX;
        const dy = touches[0].clientY - touches[1].clientY;
        return Math.sqrt(dx*dx + dy*dy);
    }
    
    function onTouchStart(e) {
        e.preventDefault();
        const touches = e.touches;
        if (touches.length === 1 && currentZoom > 1) {
            isDragging = true;
            touchStartX = touches[0].clientX - panX;
            touchStartY = touches[0].clientY - panY;
        } else if (touches.length === 2) {
            initialDistance = getTouchDistance(touches);
            initialZoom = currentZoom;
            isDragging = false;
        }
    }
    
    function onTouchMove(e) {
        e.preventDefault();
        const touches = e.touches;
        if (touches.length === 1 && isDragging && currentZoom > 1) {
            panX = touches[0].clientX - touchStartX;
            panY = touches[0].clientY - touchStartY;
            updateImageTransformWithRotation();
        } else if (touches.length === 2 && initialDistance > 0) {
            const newDistance = getTouchDistance(touches);
            const scale = newDistance / initialDistance;
            let newZoom = initialZoom * scale;
            newZoom = Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, newZoom));
            if (newZoom !== currentZoom) {
                currentZoom = newZoom;
                updateImageTransformWithRotation();
            }
        }
    }
    
    function onTouchEnd(e) {
        e.preventDefault();
        isDragging = false;
        initialDistance = 0;
        if (currentZoom <= 1.05) {
            currentZoom = 1;
            panX = 0;
            panY = 0;
            currentRotation = 0;  // ========== СБРАСЫВАЕМ ПОВОРОТ ПРИ СБРОСЕ ==========
            updateImageTransformWithRotation();
        }
    }
    
    container.addEventListener('touchstart', onTouchStart, { passive: false });
    container.addEventListener('touchmove', onTouchMove, { passive: false });
    container.addEventListener('touchend', onTouchEnd);
    img.ondblclick = resetZoom;
}

function revokeCurrentBlobUrl() {
    if (currentBlobUrl) {
        URL.revokeObjectURL(currentBlobUrl);
        currentBlobUrl = null;
    }
    if (currentImgElement) {
        currentImgElement.src = '';
        currentImgElement = null;
    }
    currentContainer = null;
}

function clearImageZoom() {
    const zoomControls = document.getElementById('zoomControls');
    if (zoomControls) zoomControls.style.display = 'none';
    currentContainer = null;
    isDragging = false;
}

// ========== ЗАГРУЗКА JSZIP ==========
let jszipLoadingPromise = null;

function loadJSZip() {
    if (typeof JSZip !== 'undefined') return Promise.resolve(true);
    if (jszipLoadingPromise) return jszipLoadingPromise;
    
    jszipLoadingPromise = new Promise(function(resolve, reject) {
        var urls = [window.APP_BASE + '/js/jszip.min.js', 'https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js'];
        var currentIndex = 0;
        function tryLoad() {
            if (currentIndex >= urls.length) { reject(new Error('Failed to load JSZip')); return; }
            var script = document.createElement('script');
            script.src = urls[currentIndex];
            script.onload = function() {
                setTimeout(function() {
                    if (typeof JSZip !== 'undefined') resolve(true);
                    else { currentIndex++; tryLoad(); }
                }, 100);
            };
            script.onerror = function() { currentIndex++; tryLoad(); };
            document.head.appendChild(script);
        }
        tryLoad();
    });
    return jszipLoadingPromise;
}

function renderZipPreview(fileUuid, fileName, contentDiv) {
    if (!contentDiv) return;
    loadJSZip().then(function() {
        var container = document.createElement('div');
        container.style.cssText = 'width:100%; height:100%; display:flex; flex-direction:column; background:#0f1529; overflow:auto;';
        var headerDiv = document.createElement('div');
        headerDiv.style.cssText = 'padding:16px 20px; background:#121a33; border-bottom:1px solid rgba(255,255,255,0.08);';
        headerDiv.innerHTML = '<div style="display:flex; align-items:center; gap:12px;"><span style="font-size:32px;">📦</span><div><div style="font-weight:600;">Содержимое архива</div><div style="font-size:12px; color:rgba(233,238,252,0.6);">' + escapeHtmlStatic(fileName) + '</div></div></div>';
        container.appendChild(headerDiv);
        var fileListDiv = document.createElement('div');
        fileListDiv.style.cssText = 'flex:1; overflow:auto; padding:20px;';
        fileListDiv.innerHTML = '<div style="text-align:center; padding:40px; color:rgba(233,238,252,0.5);">⏳ Чтение архива...</div>';
        container.appendChild(fileListDiv);
        contentDiv.innerHTML = '';
        contentDiv.appendChild(container);
        
        fetch(window.APP_BASE + '/download.php?file=' + encodeURIComponent(fileUuid) + '&preview=1')
            .then(function(response) { return response.arrayBuffer(); })
            .then(function(arrayBuffer) {
                var zip = new JSZip();
                return zip.loadAsync(arrayBuffer);
            })
            .then(function(zip) {
                var files = [], filesObj = zip.files, fileNames = Object.keys(filesObj);
                for (var i = 0; i < fileNames.length; i++) {
                    var entry = filesObj[fileNames[i]];
                    files.push({ name: fileNames[i], dir: entry.dir, date: entry.date, size: entry.dir ? 0 : (entry._data ? entry._data.uncompressedSize : 0) });
                }
                files.sort(function(a,b) { if (a.dir && !b.dir) return -1; if (!a.dir && b.dir) return 1; return a.name.localeCompare(b.name); });
                var html = '<table style="width:100%; border-collapse:collapse; font-family:monospace; font-size:13px;"><thead><tr><th>📄 Имя</th><th>Размер</th><th>Дата</th></tr></thead><tbody>';
                for (var i = 0; i < files.length; i++) {
                    var f = files[i];
                    html += '<tr><td>' + (f.dir ? '📁' : '📄') + ' ' + escapeHtmlStatic(f.name) + '<tr><td>' + (f.dir ? '—' : formatFileSizeStatic(f.size)) + '</td><td>' + (f.date ? f.date.toLocaleString('ru-RU') : '—') + '</td></tr>';
                }
                html += '</tbody></table>';
                if (files.length === 0) html = '<div style="text-align:center; padding:60px;">📭 Архив пуст</div>';
                fileListDiv.innerHTML = html;
                clearImageZoom();
            })
            .catch(function(e) {
                fileListDiv.innerHTML = '<div style="text-align:center; padding:60px; color:#ef4444;">⚠️ Не удалось прочитать ZIP архив<br><button onclick="window.location.href=\'' + window.APP_BASE + '/download.php?file=' + encodeURIComponent(fileUuid) + '&download=1\'">📥 Скачать</button></div>';
            });
    }).catch(function(e) {
        contentDiv.innerHTML = '<div style="text-align:center; padding:60px; color:#f87171;">⚠️ Библиотека ZIP не загрузилась</div>';
    });
}

// ========== ЗАГРУЗКА MAMMOTH.JS ==========
let mammothLoadingPromise = null;

function loadMammoth() {
    if (typeof mammoth !== 'undefined') return Promise.resolve(true);
    if (mammothLoadingPromise) return mammothLoadingPromise;
    mammothLoadingPromise = new Promise(function(resolve, reject) {
        var urls = [window.APP_BASE + '/js/mammoth.browser.min.js', 'https://unpkg.com/mammoth@1.4.2/mammoth.browser.min.js'];
        var idx = 0;
        function tryLoad() {
            if (idx >= urls.length) { reject(new Error('Failed to load Mammoth')); return; }
            var script = document.createElement('script');
            script.src = urls[idx];
            script.onload = function() { setTimeout(function() { if (typeof mammoth !== 'undefined') resolve(true); else { idx++; tryLoad(); } }, 100); };
            script.onerror = function() { idx++; tryLoad(); };
            document.head.appendChild(script);
        }
        tryLoad();
    });
    return mammothLoadingPromise;
}

function renderDocxPreview(fileUuid, fileName, contentDiv) {
    if (!contentDiv) return;
    
    // Старый .doc - показываем сообщение о скачивании (не пытаемся парсить)
    var ext = fileName ? fileName.split('.').pop().toLowerCase() : '';
    if (ext === 'doc') {
        contentDiv.innerHTML = 
            '<div style="display:flex; align-items:center; justify-content:center; height:100%;">' +
            '<div style="text-align:center; max-width:400px; padding:20px;">' +
            '<div style="font-size:64px; margin-bottom:16px;">📝</div>' +
            '<h3 style="margin-bottom:12px; color:#e9eefc;">' + escapeHtmlStatic(fileName) + '</h3>' +
            '<p style="margin-bottom:20px; color:rgba(233,238,252,0.7);">' +
            'Предпросмотр старых .doc файлов не поддерживается.<br>' +
            'Рекомендуем скачать файл и открыть в Microsoft Word или LibreOffice.' +
            '</p>' +
            // ✅ ИСПРАВЛЕНО: используем window.APP_BASE
            '<button onclick="window.location.href=\'' + (window.APP_BASE || '') + '/download.php?file=' + encodeURIComponent(fileUuid) + '&download=1\'" ' +
            'style="background:#4f7cff; border:none; padding:12px 28px; border-radius:8px; color:white; cursor:pointer; font-weight:500; font-size:14px;">' +
            '📥 Скачать файл</button>' +
            '</div></div>';
        clearImageZoom();
        return;
    }
    
    // DOCX
    loadMammoth().then(function() {
        var container = document.createElement('div');
        container.style.cssText = 'width:100%; height:100%; overflow:auto; background:#f5f5f0;';
        var docContainer = document.createElement('div');
        docContainer.style.cssText = 'padding:40px; max-width:900px; margin:0 auto; background:#fff; font-family:Georgia, Times, "Times New Roman", serif; line-height:1.6; color:#333; box-shadow:0 4px 12px rgba(0,0,0,0.1);';
        docContainer.className = 'docx-preview-content';
        container.appendChild(docContainer);
        contentDiv.innerHTML = '';
        contentDiv.appendChild(container);
        docContainer.innerHTML = '<div style="text-align:center; padding:60px; color:#666;"><div style="font-size:48px; margin-bottom:16px;">📄</div><div>Загрузка документа...</div></div>';
        
        fetch(window.APP_BASE + '/download.php?file=' + encodeURIComponent(fileUuid) + '&preview=1')
            .then(function(response) { return response.arrayBuffer(); })
            .then(function(arrayBuffer) {
                mammoth.convertToHtml({arrayBuffer: arrayBuffer})
                    .then(function(result) {
                        var styleHtml = '<style>' +
                            '.docx-preview-content { font-family: Georgia, Times, "Times New Roman", serif; line-height: 1.6; color: #333; }' +
                            '.docx-preview-content h1, .docx-preview-content h2, .docx-preview-content h3, .docx-preview-content h4 { margin-top: 24px; margin-bottom: 16px; font-weight: 600; line-height: 1.25; color: #1a1a1a; }' +
                            '.docx-preview-content h1 { font-size: 2em; border-bottom: 1px solid #eaecef; padding-bottom: 0.3em; }' +
                            '.docx-preview-content h2 { font-size: 1.5em; border-bottom: 1px solid #eaecef; padding-bottom: 0.3em; }' +
                            '.docx-preview-content p { margin-bottom: 16px; color: #333; }' +
                            '.docx-preview-content ul, .docx-preview-content ol { margin-bottom: 16px; padding-left: 2em; }' +
                            '.docx-preview-content table { border-collapse: collapse; width: 100%; margin-bottom: 16px; background: #fff; }' +
                            '.docx-preview-content th { background: #1e293b; color: #fff; padding: 10px 12px; border: 1px solid #334155; font-weight: 600; }' +
                            '.docx-preview-content td { border: 1px solid #cbd5e1; padding: 8px 12px; color: #1e293b; }' +
                            '.docx-preview-content tr:nth-child(even) { background: #f8fafc; }' +
                            '.docx-preview-content img { max-width: 100%; height: auto; }' +
                            '</style>';
                        docContainer.innerHTML = styleHtml + result.value;
                        clearImageZoom();
                    })
                    .catch(function(e) { 
                        docContainer.innerHTML = '<div style="text-align:center; padding:60px; color:#ef4444;">⚠️ Ошибка конвертации<br><button onclick="window.location.href=\'' + window.APP_BASE + '/download.php?file=' + encodeURIComponent(fileUuid) + '&download=1\'" style="background:#4f7cff; border:none; padding:10px 24px; border-radius:8px; color:white; cursor:pointer;">📥 Скачать</button></div>'; 
                    });
            })
            .catch(function(e) { 
                docContainer.innerHTML = '<div style="text-align:center; padding:60px; color:#ef4444;">⚠️ Ошибка загрузки</div>'; 
            });
    }).catch(function(e) {
        contentDiv.innerHTML = '<div style="text-align:center; padding:60px;">⚠️ Библиотека DOCX не загрузилась</div>';
    });
}

// ========== ЗАГРУЗКА SHEETJS ==========
let sheetjsLoadingPromise = null;

function loadSheetJS() {
    if (typeof XLSX !== 'undefined') return Promise.resolve(true);
    if (sheetjsLoadingPromise) return sheetjsLoadingPromise;
    sheetjsLoadingPromise = new Promise(function(resolve, reject) {
        var urls = [window.APP_BASE + '/js/xlsx.full.min.js', 'https://cdn.sheetjs.com/xlsx-0.20.2/package/dist/xlsx.full.min.js'];
        var idx = 0;
        function tryLoad() {
            if (idx >= urls.length) { reject(new Error('Failed to load SheetJS')); return; }
            var script = document.createElement('script');
            script.src = urls[idx];
            script.onload = function() { setTimeout(function() { if (typeof XLSX !== 'undefined') resolve(true); else { idx++; tryLoad(); } }, 100); };
            script.onerror = function() { idx++; tryLoad(); };
            document.head.appendChild(script);
        }
        tryLoad();
    });
    return sheetjsLoadingPromise;
}

// КОНТРАСТНАЯ ТАБЛИЦА ДЛЯ EXCEL (как в старой версии)
function sheetToHtml(sheet) {
    if (!sheet) return '<div style="padding:20px; text-align:center;">Пустой лист</div>';
    
    var range = XLSX.utils.decode_range(sheet['!ref'] || 'A1:A1');
    var maxRows = Math.min(range.e.r + 1, 1000);
    var maxCols = Math.min(range.e.c + 1, 30);
    
    var html = '<div style="width:100%; height:100%; overflow:auto; background:#e2e8f0;">';
    html += '<table style="border-collapse:collapse; font-family:system-ui,-apple-system,Segoe UI,Roboto,Arial,sans-serif; font-size:13px; background:#fff; width:100%; min-width:600px; box-shadow:0 1px 3px rgba(0,0,0,0.1);">';
    
    html += '<thead>';
    html += '<tr style="background:#1e293b;">';
    html += '<th style="border:1px solid #334155; padding:12px 14px; color:#ffffff; font-weight:700; text-align:center; position:sticky; top:0; z-index:10;">#</th>';
    for (var c = 0; c <= maxCols; c++) {
        var colLetter = String.fromCharCode(65 + c);
        html += '<th style="border:1px solid #334155; padding:12px 14px; color:#ffffff; font-weight:700; text-align:center; position:sticky; top:0; z-index:10;">' + colLetter + '</th>';
    }
    html += '</thead><tbody>';
    
    for (var r = 0; r <= maxRows; r++) {
        var rowBg = (r % 2 === 0) ? '#ffffff' : '#f8fafc';
        html += '<tr style="background:' + rowBg + ';">';
        html += '<td style="border:1px solid #e2e8f0; padding:10px 12px; font-weight:600; background:#f1f5f9; color:#1e293b; text-align:center;">' + (r + 1) + '</td>';
        
        for (var c = 0; c <= maxCols; c++) {
            var cellAddress = XLSX.utils.encode_cell({ r: r, c: c });
            var cell = sheet[cellAddress];
            var value = '';
            var cellStyle = '';
            var textAlign = 'left';
            
            if (cell) {
                if (cell.t === 'd') {
                    var date = new Date(cell.v);
                    if (!isNaN(date.getTime())) {
                        value = date.toLocaleDateString('ru-RU') + ' ' + date.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
                    } else {
                        value = cell.v;
                    }
                } else if (cell.t === 'n') {
                    value = cell.v;
                    if (typeof value === 'number') {
                        if (Math.floor(value) === value) {
                            value = value.toString();
                        } else {
                            value = value.toFixed(2);
                        }
                    }
                    textAlign = 'right';
                    cellStyle = 'color:#0f172a; font-weight:500;';
                } else if (cell.t === 'b') {
                    value = cell.v ? 'TRUE' : 'FALSE';
                    textAlign = 'center';
                    cellStyle = 'color:#0f172a; font-weight:500;';
                } else {
                    value = cell.v;
                    cellStyle = 'color:#1e293b;';
                }
                
                if (typeof value === 'string' && value.length > 200) {
                    value = value.substring(0, 200) + '…';
                }
            }
            
            var isEmpty = !cell || (value === '' || value === undefined);
            if (isEmpty) {
                cellStyle += 'color:#94a3b8; font-style:italic;';
                value = '—';
            }
            
            html += '<td style="border:1px solid #e2e8f0; padding:10px 12px; max-width:300px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; text-align:' + textAlign + '; ' + cellStyle + '" title="' + escapeHtmlStatic(String(value)) + '">' + (escapeHtmlStatic(String(value)) || '&nbsp;') + '</td>';
        }
        html += '<tr>';
    }
    html += '</tbody></table></div>';
    
    return html;
}

function renderExcelPreview(fileUuid, fileName, contentDiv) {
    if (!contentDiv) return;
    loadSheetJS().then(function() {
        var container = document.createElement('div');
        container.style.cssText = 'width:100%; height:100%; display:flex; flex-direction:column; background:#e2e8f0; overflow:hidden;';
        
        var sheetContainer = document.createElement('div');
        sheetContainer.style.cssText = 'flex:1; overflow:auto; padding:0; background:#e2e8f0;';
        sheetContainer.innerHTML = '<div style="text-align:center; padding:60px; color:#64748b; font-size:14px;">⏳ Загрузка и парсинг файла...</div>';
        
        container.appendChild(sheetContainer);
        contentDiv.innerHTML = '';
        contentDiv.appendChild(container);
        
        fetch(window.APP_BASE + '/download.php?file=' + encodeURIComponent(fileUuid) + '&preview=1')
            .then(function(response) { return response.arrayBuffer(); })
            .then(function(arrayBuffer) {
                var workbook = XLSX.read(new Uint8Array(arrayBuffer), { type: 'array', sheetRows: 1000, cellDates: true, dateNF: 'dd.mm.yyyy hh:mm:ss' });
                var sheetNames = workbook.SheetNames;
                var html = '';
                
                if (sheetNames.length > 1) {
                    html += '<div style="padding:12px 20px; background:#f1f5f9; border-bottom:1px solid #cbd5e1; position:sticky; top:0; z-index:20;">';
                    html += '<div style="display:flex; gap:8px; flex-wrap:wrap;">';
                    for (var idx = 0; idx < sheetNames.length; idx++) {
                        html += '<button class="excel-sheet-tab" data-sheet-index="' + idx + '" style="padding:8px 16px; border:none; background:' + (idx === 0 ? '#4f7cff' : '#e2e8f0') + '; color:' + (idx === 0 ? '#fff' : '#334155') + '; border-radius:8px; cursor:pointer; transition:all 0.2s; font-size:13px; font-weight:500;">📄 ' + escapeHtmlStatic(sheetNames[idx]) + '</button>';
                    }
                    html += '</div></div>';
                }
                
                var firstSheet = workbook.Sheets[sheetNames[0]];
                html += sheetToHtml(firstSheet);
                sheetContainer.innerHTML = html;
                clearImageZoom();
                
                // Переключение листов
                var tabs = document.querySelectorAll('.excel-sheet-tab');
                for (var i = 0; i < tabs.length; i++) {
                    tabs[i].addEventListener('click', (function(btn, idx) {
                        return function() {
                            var sheet = workbook.Sheets[sheetNames[idx]];
                            var newTableHtml = sheetToHtml(sheet);
                            
                            var allTabs = document.querySelectorAll('.excel-sheet-tab');
                            for (var j = 0; j < allTabs.length; j++) {
                                allTabs[j].style.background = '#e2e8f0';
                                allTabs[j].style.color = '#334155';
                            }
                            btn.style.background = '#4f7cff';
                            btn.style.color = '#fff';
                            
                            // Сохраняем заголовок с табами
                            var tabsDiv = sheetContainer.querySelector('div:first-child');
                            if (tabsDiv && tabsDiv.innerHTML.indexOf('excel-sheet-tab') > -1) {
                                sheetContainer.innerHTML = tabsDiv.outerHTML + newTableHtml;
                                // Перепривязываем события
                                var newTabs = document.querySelectorAll('.excel-sheet-tab');
                                for (var k = 0; k < newTabs.length; k++) {
                                    (function(btn2, idx2) {
                                        newTabs[k].addEventListener('click', function() {
                                            var sheet2 = workbook.Sheets[sheetNames[idx2]];
                                            var newTableHtml2 = sheetToHtml(sheet2);
                                            var allTabs2 = document.querySelectorAll('.excel-sheet-tab');
                                            for (var j2 = 0; j2 < allTabs2.length; j2++) {
                                                allTabs2[j2].style.background = '#e2e8f0';
                                                allTabs2[j2].style.color = '#334155';
                                            }
                                            btn2.style.background = '#4f7cff';
                                            btn2.style.color = '#fff';
                                            var tabsDiv2 = sheetContainer.querySelector('div:first-child');
                                            sheetContainer.innerHTML = tabsDiv2.outerHTML + newTableHtml2;
                                        });
                                    })(newTabs[k], k);
                                }
                            } else {
                                sheetContainer.innerHTML = newTableHtml;
                            }
                        };
                    })(tabs[i], i));
                }
            })
            .catch(function(e) { 
                sheetContainer.innerHTML = '<div style="text-align:center; padding:60px; color:#ef4444;">⚠️ Ошибка парсинга Excel<br><button onclick="window.location.href=\'' + window.APP_BASE + '/download.php?file=' + encodeURIComponent(fileUuid) + '&download=1\'" style="background:#4f7cff; border:none; padding:10px 24px; border-radius:8px; color:white; cursor:pointer;">📥 Скачать</button></div>'; 
            });
    }).catch(function(e) {
        contentDiv.innerHTML = '<div style="text-align:center; padding:60px;">⚠️ Библиотека Excel не загрузилась</div>';
    });
}

function renderMediaPreview(fileUuid, fileName, fileSize, fileMime, contentDiv) {
    if (!contentDiv) return;
    var isAudio = fileMime && fileMime.startsWith('audio/');
    var isVideo = fileMime && fileMime.startsWith('video/');
    var container = document.createElement('div');
    container.style.cssText = 'width:100%; height:100%; display:flex; flex-direction:column; align-items:center; justify-content:center; background:#000;';
    var mediaUrl = window.APP_BASE + '/download.php?file=' + encodeURIComponent(fileUuid) + '&preview=1';
    if (isAudio) {
        var audio = document.createElement('audio');
        audio.controls = true;
        audio.style.cssText = 'width:90%; max-width:600px; margin:20px;';
        audio.src = mediaUrl;
        container.appendChild(audio);
        contentDiv.innerHTML = '';
        contentDiv.appendChild(container);
    } else if (isVideo) {
        var video = document.createElement('video');
        video.controls = true;
        video.style.cssText = 'width:100%; height:100%; object-fit:contain;';
        video.src = mediaUrl;
        container.appendChild(video);
        contentDiv.innerHTML = '';
        contentDiv.appendChild(container);
    }
    clearImageZoom();
}

function renderImagePreview(fileUuid, fileName, fileSize, fileMime, contentDiv) {
    if (!contentDiv) return;
    
    fetch(window.APP_BASE + '/download.php?file=' + encodeURIComponent(fileUuid) + '&preview=1')
        .then(function(response) { return response.blob(); })
        .then(function(blob) {
            revokeCurrentBlobUrl();
            currentBlobUrl = URL.createObjectURL(blob);
            var img = document.createElement('img');
            img.style.cssText = 'max-width:100%; max-height:100%; object-fit:contain; display:block; margin:auto;';
            var container = document.createElement('div');
            container.style.cssText = 'display:flex; align-items:center; justify-content:center; width:100%; height:100%; overflow:hidden; position:relative;';
            container.appendChild(img);
            if (contentDiv) {
                contentDiv.innerHTML = '';
                contentDiv.appendChild(container);
            }
            img.onload = function() { setupImageZoom(img, container); };
            img.onerror = function() {
                revokeCurrentBlobUrl();
                if (contentDiv) contentDiv.innerHTML = '<div style="text-align:center; padding:40px; color:#ff6b6b;"><div style="font-size:48px; margin-bottom:16px;">🖼️</div><h3>Ошибка загрузки изображения</h3></div>';
            };
            img.src = currentBlobUrl;
        })
        .catch(function(error) {
            if (contentDiv) contentDiv.innerHTML = '<div style="text-align:center; padding:40px; color:#ff6b6b;">⚠️ Ошибка загрузки</div>';
        });
}

// ========== ОСНОВНАЯ ФУНКЦИЯ ==========
// ==================== BLOCK START: showFilePreview v6.6 (Fixed recursive call) ====================
window.showFilePreview = function(fileUuid, fileName, fileSize, fileMime) {
    safeLog('[file_preview] showFilePreview called:', { fileUuid: fileUuid, fileName: fileName });
    
    if (!fileUuid) return;
    
    // Защита от рекурсии
    if (window._processingFilePreview) {
        safeLog('[file_preview] Already processing, skipping recursive call');
        return;
    }
    window._processingFilePreview = true;
    
    try {
        var modal = createModal();
        var contentDiv = document.getElementById('previewContent');
        var fileNameSpan = document.getElementById('previewFileName');
        var fileMetaSpan = document.getElementById('previewFileMeta');
        var downloadBtn = document.getElementById('previewDownloadBtn');
        
        revokeCurrentBlobUrl();
        clearImageZoom();
        
        if (fileNameSpan) fileNameSpan.innerText = fileName || 'Файл';
        if (fileMetaSpan) fileMetaSpan.innerText = formatFileSizeStatic(fileSize);
        if (downloadBtn) {
            downloadBtn.onclick = function(e) {
                e.preventDefault();
                window.location.href = window.APP_BASE + '/download.php?file=' + encodeURIComponent(fileUuid) + '&download=1';
            };
        }
        
        if (contentDiv) contentDiv.innerHTML = '<div class="preview-loading">Загрузка...</div>';
        modal.style.display = 'flex';
        
        var ext = fileName ? fileName.split('.').pop().toLowerCase() : '';
        
        // .doc файлы
        if (ext === 'doc') {
            renderDocxPreview(fileUuid, fileName, contentDiv);
            window._processingFilePreview = false;
            return;
        }
        
        // Изображения
        var isImageByMime = fileMime && fileMime.startsWith('image/');
        var imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico'];
        var isImageByExt = imageExts.indexOf(ext) !== -1;
        
        if (isImageByMime || isImageByExt) {
            safeLog('[file_preview] Detected as IMAGE, using renderImagePreview');
            renderImagePreview(fileUuid, fileName, fileSize, fileMime, contentDiv);
            window._processingFilePreview = false;
            return;
        }
        
        var isExcel = (fileMime && (fileMime.includes('spreadsheetml') || fileMime === 'application/vnd.ms-excel')) || ext === 'xlsx' || ext === 'xls' || ext === 'xlsm' || ext === 'csv';
        var isDocx = (fileMime === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') || ext === 'docx';
        var isZip = (fileMime === 'application/zip' || fileMime === 'application/x-zip-compressed') || ext === 'zip' || ext === 'rar' || ext === '7z';
        var isAudio = fileMime && fileMime.startsWith('audio/');
        var isVideo = fileMime && fileMime.startsWith('video/');
        var isPdf = fileMime === 'application/pdf' || ext === 'pdf';
        
        // ZIP
        if (isZip) {
            renderZipPreview(fileUuid, fileName, contentDiv);
            window._processingFilePreview = false;
            return;
        }
        
        // DOCX
        if (isDocx) {
            renderDocxPreview(fileUuid, fileName, contentDiv);
            window._processingFilePreview = false;
            return;
        }
        
        // Excel
        if (isExcel) {
            renderExcelPreview(fileUuid, fileName, contentDiv);
            window._processingFilePreview = false;
            return;
        }
        
        // Аудио/Видео
        if (isAudio || isVideo) {
            renderMediaPreview(fileUuid, fileName, fileSize, fileMime, contentDiv);
            window._processingFilePreview = false;
            return;
        }
        
        // PDF
        if (isPdf) {
            var isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
            var pdfUrl = window.APP_BASE + '/download.php?file=' + encodeURIComponent(fileUuid) + '&preview=1&name=' + encodeURIComponent(fileName || 'file.pdf');
            
            var wrapper = document.createElement('div');
            wrapper.style.cssText = 'width:100%; height:100%; display:flex; flex-direction:column; background:#fff; position:relative;';
            
            var viewer = document.createElement('iframe');
            viewer.src = pdfUrl;
            viewer.style.cssText = 'flex:1; width:100%; border:none; background:#fff;';
            viewer.setAttribute('allowfullscreen', '');
            viewer.setAttribute('loading', 'eager');
            
            wrapper.appendChild(viewer);
            
            if (isMobile) {
                var fallbackBtn = document.createElement('a');
                fallbackBtn.href = pdfUrl;
                fallbackBtn.target = '_top';
                fallbackBtn.textContent = '📄 Если не отображается — нажмите здесь';
                fallbackBtn.style.cssText = 'padding:14px; background:#4f7cff; color:#fff; text-align:center; text-decoration:none; font-size:14px; font-weight:500; display:block;';
                wrapper.appendChild(fallbackBtn);
            }
            
            if (contentDiv) {
                contentDiv.innerHTML = '';
                contentDiv.appendChild(wrapper);
            }
            clearImageZoom();
            window._processingFilePreview = false;
            return;
        }
        
        // Текстовые файлы по расширению
                // ==================== BLOCK START: Text and HTML files preview v6.7 ====================
        // ver.6.6 - Базовая версия
        // ver.6.7 (2026-06-07) - ДОБАВЛЕНА ПОДДЕРЖКА HTML ФАЙЛОВ
        // - HTML файлы рендерятся в iframe с sandbox для безопасности
        // - Добавлены расширения .html, .htm

        var textExts = ['txt', 'md', 'json', 'xml', 'ini', 'conf', 'log'];
        var htmlExts = ['html', 'htm'];
        var isHtmlFile = (htmlExts.indexOf(ext) !== -1);
        
        // HTML файлы — рендерим в iframe
        if (isHtmlFile) {
            safeLog('[file_preview] Detected as HTML file, rendering in iframe');
            var htmlUrl = window.APP_BASE + '/download.php?file=' + encodeURIComponent(fileUuid) + '&preview=1&inline=1';
            
            var wrapper = document.createElement('div');
            wrapper.style.cssText = 'width:100%; height:100%; display:flex; flex-direction:column; background:#fff; position:relative;';
            
            // Информационная панель
            var infoBar = document.createElement('div');
            infoBar.style.cssText = 'padding:8px 16px; background:#f1f5f9; border-bottom:1px solid #cbd5e1; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;';
            infoBar.innerHTML = `
                <div style="display:flex; align-items:center; gap:12px;">
                    <span style="font-size:14px; font-weight:500;color: #000000 !important;">🌐 ${escapeHtmlStatic(fileName)}</span>
                    <span style="font-size:11px; color:#64748b;">${formatFileSizeStatic(fileSize)}</span>
                </div>
                <div style="display:flex; gap:8px;">
                    <button class="preview-btn" onclick="window.location.href='${window.APP_BASE}/download.php?file=${encodeURIComponent(fileUuid)}&download=1'" 
                            style="background:#4f7cff; border:none; padding:6px 14px; border-radius:6px; color:white; cursor:pointer; font-size:12px;">📥 Скачать</button>
                    <button class="preview-btn" onclick="window.open('${htmlUrl}', '_blank')" 
                            style="background:#10b981; border:none; padding:6px 14px; border-radius:6px; color:white; cursor:pointer; font-size:12px;">🔗 Открыть в новой вкладке</button>
                </div>
            `;
            wrapper.appendChild(infoBar);
            
            // iframe для безопасного просмотра HTML
            var iframe = document.createElement('iframe');
            iframe.src = htmlUrl;
            iframe.style.cssText = 'flex:1; width:100%; border:none; background:#fff;';
            // sandbox ограничивает возможности скриптов для безопасности
            iframe.sandbox = 'allow-same-origin allow-scripts allow-popups allow-forms allow-modals';
            iframe.setAttribute('title', fileName);
            
            wrapper.appendChild(iframe);
            
            if (contentDiv) {
                contentDiv.innerHTML = '';
                contentDiv.appendChild(wrapper);
            }
            clearImageZoom();
            window._processingFilePreview = false;
            return;
        }
        
        // Обычные текстовые файлы
        if (textExts.indexOf(ext) !== -1) {
            fetch(window.APP_BASE + '/download.php?file=' + encodeURIComponent(fileUuid) + '&preview=1')
                .then(function(response) { return response.text(); })
                .then(function(text) {
                    var truncated = false;
                    if (text.length > 500000) {
                        text = text.substring(0, 500000);
                        truncated = true;
                    }
                    var html = '<div style="width:100%; height:100%; overflow:auto; background:#0f1529;"><div style="padding:20px;">';
                    html += '<div style="margin-bottom:16px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">';
                    html += '<div><span style="font-size:14px; background:#1e293b; padding:4px 12px; border-radius:20px;">📄 ' + escapeHtmlStatic(ext.toUpperCase()) + '</span>';
                    html += '<span style="font-size:11px; color:rgba(233,238,252,0.5); margin-left:12px;">Размер: ' + formatFileSizeStatic(fileSize) + '</span></div>';
                    html += '<button class="preview-btn" onclick="window.location.href=\'' + window.APP_BASE + '/download.php?file=' + encodeURIComponent(fileUuid) + '&download=1\'" style="background:#4f7cff; border:none; padding:6px 14px; border-radius:8px; color:white; cursor:pointer;">📥 Скачать</button>';
                    html += '</div>';
                    html += '<pre style="background:#0b1020; border:1px solid rgba(255,255,255,0.1); border-radius:8px; padding:16px; overflow-x:auto; font-family:monospace; font-size:12px; color:#e2e8f0; white-space:pre-wrap; word-break:break-all; margin:0;">' + escapeHtmlStatic(text) + '</pre>';
                    if (truncated) html += '<p style="color:#f59e0b; font-size:11px; margin-top:12px;">⚠️ Файл слишком большой, показаны первые 500 КБ</p>';
                    html += '</div></div>';
                    if (contentDiv) contentDiv.innerHTML = html;
                    clearImageZoom();
                })
                .catch(function(error) {
                    if (contentDiv) contentDiv.innerHTML = '<div style="text-align:center; padding:60px;">⚠️ Не удалось загрузить текстовый файл</div>';
                });
            window._processingFilePreview = false;
            return;
        }
        
        // Для всех остальных типов - анализатор
        fetch(window.APP_BASE + '/lib/file_analyzer_ajax.php?uuid=' + encodeURIComponent(fileUuid) + '&size=65536')
            .then(function(response) { if (!response.ok) throw new Error('HTTP ' + response.status); return response.json(); })
            .then(function(analysis) {
                if (analysis.success && analysis.category === 'text' && analysis.text_content) {
                    var textContent = analysis.text_content;
                    var truncated = false;
                    if (textContent.length > 500000) {
                        textContent = textContent.substring(0, 500000);
                        truncated = true;
                    }
                    textContent = escapeHtmlStatic(textContent);
                    
                    var html = '<div style="width:100%; height:100%; overflow:auto; background:#0f1529;">';
                    html += '<div style="padding:20px;">';
                    html += '<div style="margin-bottom:16px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">';
                    html += '<div><span style="font-size:14px; background:#1e293b; padding:4px 12px; border-radius:20px;">📄 ' + escapeHtmlStatic(analysis.format_name) + '</span>';
                    html += '<span style="font-size:11px; color:rgba(233,238,252,0.5); margin-left:12px;">Размер: ' + formatFileSizeStatic(analysis.file_size) + '</span></div>';
                    html += '<button class="preview-btn" onclick="window.location.href=\'' + window.APP_BASE + '/download.php?file=' + encodeURIComponent(fileUuid) + '&download=1\'" style="background:#4f7cff; border:none; padding:6px 14px; border-radius:8px; color:white; cursor:pointer;">📥 Скачать</button>';
                    html += '</div>';
                    html += '<pre style="background:#0b1020; border:1px solid rgba(255,255,255,0.1); border-radius:8px; padding:16px; overflow-x:auto; font-family:monospace; font-size:12px; color:#e2e8f0; white-space:pre-wrap; word-break:break-all; margin:0;">' + textContent + '</pre>';
                    if (truncated) html += '<p style="color:#f59e0b; font-size:11px; margin-top:12px;">⚠️ Файл слишком большой, показаны первые 500 КБ</p>';
                    html += '</div></div>';
                    if (contentDiv) contentDiv.innerHTML = html;
                    clearImageZoom();
                    return;
                }
                
                if (analysis.success && analysis.hex_preview) {
                    var detectedInfo = '';
                    if (analysis.signature_match) {
                        detectedInfo = '<div style="background:#1e293b; padding:8px 12px; border-radius:8px; margin-bottom:16px;">🔍 Определено: ' + escapeHtmlStatic(analysis.format_name) + ' (' + escapeHtmlStatic(analysis.mime) + ')</div>';
                    }
                    
                    var html = '<div style="width:100%; height:100%; overflow:auto; padding:20px; background:#0f1529;">';
                    html += '<div style="max-width:1200px; margin:0 auto;">';
                    html += '<div style="margin-bottom:20px;">';
                    html += '<div style="font-size:48px; margin-bottom:12px;">🔍</div>';
                    html += '<h3>' + escapeHtmlStatic(fileName) + '</h3>';
                    html += '<p>Размер: ' + formatFileSizeStatic(fileSize) + '</p>';
                    html += '<p>MIME: ' + escapeHtmlStatic(fileMime) + '</p>';
                    html += detectedInfo;
                    html += '</div>';
                    html += '<div style="margin-bottom:24px;"><div style="font-family:monospace; font-size:11px; background:#0b1020; border-radius:8px; padding:12px; overflow-x:auto;">';
                    html += '<div style="margin-bottom:8px; color:#60a5fa;">📋 HEX-дамп (первые ' + analysis.preview_size + ' байт):</div>';
                    html += '<pre style="color:#e2e8f0; margin:0; font-size:11px; line-height:1.5;">' + escapeHtmlStatic(analysis.hex_preview) + '</pre>';
                    html += '</div></div>';
                    html += '<div style="display:flex; gap:12px; justify-content:center;">';
                    html += '<button onclick="window.location.href=\'' + window.APP_BASE + '/download.php?file=' + encodeURIComponent(fileUuid) + '&download=1\'" style="background:#4f7cff; border:none; padding:10px 20px; border-radius:8px; color:white; cursor:pointer;">📥 Скачать</button>';
                    html += '</div></div></div>';
                    if (contentDiv) contentDiv.innerHTML = html;
                    clearImageZoom();
                    return;
                }
                
                fetch(window.APP_BASE + '/download.php?file=' + encodeURIComponent(fileUuid) + '&preview=1')
                    .then(function(response) { return response.arrayBuffer(); })
                    .then(function(arrayBuffer) {
                        var data = new Uint8Array(arrayBuffer);
                        var previewSize = Math.min(data.length, 1024);
                        var hexLines = '';
                        for (var offset = 0; offset < previewSize; offset += 16) {
                            var chunk = data.slice(offset, Math.min(offset + 16, previewSize));
                            var hex = Array.from(chunk).map(function(b) { return b.toString(16).padStart(2, '0'); }).join(' ');
                            hex = hex.padEnd(47, ' ');
                            var ascii = '';
                            for (var i = 0; i < chunk.length; i++) {
                                var code = chunk[i];
                                ascii += (code >= 32 && code <= 126) ? String.fromCharCode(code) : '.';
                            }
                            hexLines += offset.toString(16).padStart(8, '0') + ':  ' + hex + '  ' + ascii + '\n';
                        }
                        
                        var html = '<div style="width:100%; height:100%; overflow:auto; background:#0f1529;"><div style="padding:20px;">';
                        html += '<div style="margin-bottom:16px; display:flex; justify-content:space-between;"><div><span style="font-size:14px; background:#1e293b; padding:4px 12px; border-radius:20px;">🔍 Неизвестный бинарный файл</span>';
                        html += '<span style="font-size:11px; color:rgba(233,238,252,0.5); margin-left:12px;">Размер: ' + formatFileSizeStatic(data.length) + '</span></div>';
                        html += '<button onclick="window.location.href=\'' + window.APP_BASE + '/download.php?file=' + encodeURIComponent(fileUuid) + '&download=1\'" style="background:#4f7cff; border:none; padding:6px 14px; border-radius:8px; color:white; cursor:pointer;">📥 Скачать</button></div>';
                        html += '<p style="color:rgba(233,238,252,0.5); margin-bottom:16px;">MIME: ' + escapeHtmlStatic(fileMime) + '</p>';
                        html += '<div style="margin-bottom:24px;"><div style="font-family:monospace; font-size:11px; background:#0b1020; border-radius:8px; padding:12px; overflow-x:auto;">';
                        html += '<div style="margin-bottom:8px; color:#60a5fa;">📋 HEX-дамп (первые ' + previewSize + ' байт):</div>';
                        html += '<pre style="color:#e2e8f0; margin:0; font-size:11px;">' + escapeHtmlStatic(hexLines) + '</pre></div></div>';
                        html += '</div></div>';
                        if (contentDiv) contentDiv.innerHTML = html;
                        clearImageZoom();
                    })
                    .catch(function(e) {
                        var errorHtml = '<div style="text-align:center; padding:60px;">⚠️ Не удалось загрузить файл<br><button onclick="window.location.href=\'' + window.APP_BASE + '/download.php?file=' + encodeURIComponent(fileUuid) + '&download=1\'">📥 Скачать</button></div>';
                        if (contentDiv) contentDiv.innerHTML = errorHtml;
                    });
            })
            .catch(function(error) {
                var errorHtml = '<div style="text-align:center; padding:60px;">⚠️ Ошибка анализатора<br><button onclick="window.location.href=\'' + window.APP_BASE + '/download.php?file=' + encodeURIComponent(fileUuid) + '&download=1\'">📥 Скачать</button></div>';
                if (contentDiv) contentDiv.innerHTML = errorHtml;
            });
        
    } finally {
        setTimeout(function() {
            window._processingFilePreview = false;
        }, 100);
    }
};
// ==================== BLOCK END: showFilePreview v6.6 ====================

function closeModal() {
    if (modal) {
        modal.style.display = 'none';
        var contentDiv = document.getElementById('previewContent');
        if (contentDiv) contentDiv.innerHTML = '<div class="preview-loading">Загрузка...</div>';
        revokeCurrentBlobUrl();
        clearImageZoom();
    }
}

function formatFileSizeStatic(bytes) {
    if (typeof bytes !== 'number') bytes = parseInt(bytes, 10);
    if (isNaN(bytes)) return '';
    if (bytes >= 1073741824) return (bytes / 1073741824).toFixed(2) + ' GB';
    if (bytes >= 1048576) return (bytes / 1048576).toFixed(2) + ' MB';
    if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return bytes + ' B';
}

function escapeHtmlStatic(text) {
    if (!text) return '';
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

window.closeFilePreview = closeModal;

safeLog('[file_preview] file_preview.js v6.5 loaded with contrast Excel and .doc support');
})();