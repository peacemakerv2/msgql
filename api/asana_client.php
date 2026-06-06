<?php
// asana_client.php
// Клиент для работы с Asana REST API
// Версия: 2.9 - ГИБРИДНАЯ (добавлены методы для attachments)
// СТАРАЯ ЛОГИКА РАБОТЫ С СЕРВЕРОМ СОХРАНЕНА ПОЛНОСТЬЮ

class AsanaClient {
    private $accessToken;
    private $workspaceGid;
    private $apiBase;
    private $lastRequestTime = 0;
    private $requestDelay = ASANA_REQUEST_DELAY;
    
    public function __construct($accessToken, $workspaceGid) {
        $this->accessToken = $accessToken;
        $this->workspaceGid = $workspaceGid;
        $this->apiBase = ASANA_API_BASE;
    }
    
    private function request($endpoint, $method = 'GET', $data = null, $params = []) {
        // Rate limiting - СТАРАЯ ЛОГИКА СОХРАНЕНА
        $now = microtime(true);
        $timeSinceLast = $now - $this->lastRequestTime;
        if ($timeSinceLast < $this->requestDelay) {
            $time_to_sleep = (int)($this->requestDelay - $timeSinceLast) * 1000000;
            usleep($time_to_sleep);
        }
        
        $url = $this->apiBase . $endpoint;
        if (!empty($params) && $method === 'GET') {
            $url .= '?' . http_build_query($params);
        }
        
        log_debug("[ASANA_CLIENT] Request: {$method} {$url}");
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, ASANA_API_TIMEOUT);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, ASANA_API_CONNECT_TIMEOUT);
        
        $headers = ['Authorization: Bearer ' . $this->accessToken];
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                $headers[] = 'Content-Type: application/json';
            }
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                $headers[] = 'Content-Type: application/json';
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        $this->lastRequestTime = microtime(true);
        
        if ($error) {
            log_error("[ASANA_CLIENT] cURL Error: {$error}");
            throw new Exception("cURL Error: {$error}");
        }
        
        if ($httpCode !== 200 && $httpCode !== 201) {
            $err = json_decode($response, true);
            $msg = $err['errors'][0]['message'] ?? "HTTP {$httpCode}";
            log_error("[ASANA_CLIENT] API Error: {$msg}");
            throw new Exception($msg);
        }
        
        return json_decode($response, true);
    }
    
    /**
     * Скачивание файла по URL с поддержкой Resume
     * СТАРАЯ ЛОГИКА СОХРАНЕНА, добавлен ретрай при таймауте
     */
    public function downloadFile($url, $destPath, $retryCount = 0) {
        log_debug("[ASANA_CLIENT] Downloading file from URL: " . substr($url, 0, 100) . "...");
        
        // Проверяем существующий файл для resume
        $existingSize = file_exists($destPath) ? filesize($destPath) : 0;
        $resumeOffset = $existingSize;
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, ASANA_DOWNLOAD_TIMEOUT);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, ASANA_DOWNLOAD_CONNECT_TIMEOUT);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        $headers = ['Authorization: Bearer ' . $this->accessToken];
        if ($resumeOffset > 0) {
            $headers[] = "Range: bytes={$resumeOffset}-";
            log_debug("[ASANA_CLIENT] Resuming download from offset {$resumeOffset}");
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        $fp = fopen($destPath, $resumeOffset > 0 ? 'ab' : 'wb');
        if (!$fp) {
            throw new Exception("Failed to open file for writing: {$destPath}");
        }
        curl_setopt($ch, CURLOPT_FILE, $fp);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $downloadedSize = filesize($destPath);
        
        curl_close($ch);
        fclose($fp);
        
        if ($error) {
            log_error("[ASANA_CLIENT] Download cURL Error: {$error}");
            if (strpos($error, 'Timeout') !== false && $retryCount < 3) {
                log_debug("[ASANA_CLIENT] Timeout, will retry (attempt " . ($retryCount + 1) . ")");
                throw new Exception("Download timeout, will retry: {$error}");
            }
            throw new Exception("Download cURL Error: {$error}");
        }
        
        if ($httpCode !== 200 && $httpCode !== 206 && $httpCode !== 201) {
            @unlink($destPath);
            throw new Exception("Download failed: HTTP {$httpCode}");
        }
        
        if ($downloadedSize == 0) {
            @unlink($destPath);
            throw new Exception("Downloaded file is empty");
        }
        
        log_info("[ASANA_CLIENT] Downloaded {$downloadedSize} bytes to {$destPath}");
        return $downloadedSize;
    }
    
    /**
     * Получение списка проектов
     */
    public function getProjects($limit = 100, $offset = null) {
        $params = [
            'workspace' => $this->workspaceGid,
            'limit' => min($limit, 100),
            'opt_fields' => 'gid,name,notes,created_at,modified_at,owner,team,public,archived'
        ];
        if ($offset) $params['offset'] = $offset;
        
        $result = $this->request('/projects', 'GET', null, $params);
        return $result['data'] ?? [];
    }
    
    /**
     * Получение всех проектов (с пагинацией)
     */
    public function getAllProjects() {
        $projects = [];
        $offset = null;
        do {
            $batch = $this->getProjects(100, $offset);
            $projects = array_merge($projects, $batch);
            $offset = $batch['next_page']['offset'] ?? null;
            if ($offset) usleep(ASANA_REQUEST_DELAY * 1000000);
        } while ($offset);
        
        log_info("[ASANA_CLIENT] Found " . count($projects) . " projects");
        return $projects;
    }
    
    /**
     * Получение информации о проекте
     */
    public function getProject($projectGid) {
        $params = [
            'opt_fields' => 'gid,name,notes,created_at,modified_at,owner,team,public,archived,icon,default_view,color'
        ];
        $result = $this->request("/projects/{$projectGid}", 'GET', null, $params);
        return $result['data'] ?? null;
    }
    
    /**
     * Получение задач проекта (упрощённый, без подзадач)
     */
    public function getTasks($projectGid, $limit = 100, $offset = null) {
        $params = [
            'project' => $projectGid,
            'limit' => min($limit, 100),
            'opt_fields' => 'gid,name,notes,completed,completed_at,created_at,modified_at,due_on,start_on,assignee,parent'
        ];
        if ($offset) $params['offset'] = $offset;
        
        $result = $this->request('/tasks', 'GET', null, $params);
        return $result['data'] ?? [];
    }
    
    /**
     * Получение всех задач проекта (плоский список, с пагинацией)
     * СТАРАЯ ЛОГИКА - используем для импорта
     */
    public function getAllTasksFlat($projectGid) {
        log_info("[ASANA_CLIENT] Getting all tasks for project {$projectGid}");
        
        $tasks = [];
        $offset = null;
        do {
            $batch = $this->getTasks($projectGid, 100, $offset);
            $tasks = array_merge($tasks, $batch);
            $offset = $batch['next_page']['offset'] ?? null;
            if ($offset) usleep(ASANA_REQUEST_DELAY * 1000000);
        } while ($offset);
        
        log_info("[ASANA_CLIENT] Found " . count($tasks) . " tasks (flat) for project {$projectGid}");
        return $tasks;
    }

    /**
     * Получение всех задач проекта с иерархической структурой (включая подзадачи)
     * Версия: 2.0 - рекурсивное получение подзадач через getSubtasks()
     */
    public function getAllTasksHierarchical($projectGid) {
        log_info("[ASANA_CLIENT] Getting hierarchical tasks for project {$projectGid}");
        
        $flatTasks = $this->getAllTasksFlat($projectGid);
        log_debug("[ASANA_CLIENT] Got " . count($flatTasks) . " root level tasks");
        
        $rootTasks = [];
        
        foreach ($flatTasks as $task) {
            log_debug("[ASANA_CLIENT] Fetching subtasks for task: {$task['gid']} - {$task['name']}");
            $task['subtasks'] = $this->getSubtasks($task['gid']);
            $rootTasks[] = $task;
        }
        
        $totalSubtasks = 0;
        $countSubtasks = function($tasks) use (&$countSubtasks, &$totalSubtasks) {
            foreach ($tasks as $task) {
                if (!empty($task['subtasks'])) {
                    $totalSubtasks += count($task['subtasks']);
                    $countSubtasks($task['subtasks']);
                }
            }
        };
        $countSubtasks($rootTasks);
        
        log_info("[ASANA_CLIENT] Built hierarchy: " . count($rootTasks) . " root tasks, total subtasks: {$totalSubtasks}");
        return $rootTasks;
    }

    /**
     * Получение подзадач задачи
     * Версия: 1.0 - рекурсивное получение всех подзадач
     */
    public function getSubtasks($taskGid) {
        $params = [
            'opt_fields' => 'gid,name,notes,completed,completed_at,created_at,modified_at,due_on,start_on,assignee,parent,permalink_url,resource_subtype'
        ];
        $result = $this->request("/tasks/{$taskGid}/subtasks", 'GET', null, $params);
        $subtasks = $result['data'] ?? [];
        
        foreach ($subtasks as &$subtask) {
            $subtask['subtasks'] = $this->getSubtasks($subtask['gid']);
        }
        
        log_debug("[ASANA_CLIENT] Found " . count($subtasks) . " direct subtasks for task {$taskGid}");
        return $subtasks;
    }
    
    /**
     * Получение информации о задаче
     */
    public function getTask($taskGid) {
        $params = [
            'opt_fields' => 'gid,name,notes,completed,completed_at,created_at,modified_at,due_on,start_on,assignee,parent,permalink_url,resource_subtype'
        ];
        $result = $this->request("/tasks/{$taskGid}", 'GET', null, $params);
        return $result['data'] ?? null;
    }
    
    /**
     * Получение сторис (сообщений/комментариев) к задаче
     * СТАРАЯ ЛОГИКА - получаем stories с attachments
     */
    public function getStories($taskGid, $limit = 50, $offset = null) {
        $params = [
            'limit' => min($limit, 50),
            'opt_fields' => 'gid,created_at,created_by,text,type,resource_subtype,html_text,attachments'
        ];
        if ($offset) $params['offset'] = $offset;
        
        $result = $this->request("/tasks/{$taskGid}/stories", 'GET', null, $params);
        return $result['data'] ?? [];
    }
    
    /**
     * Получение всех сторис задачи (с пагинацией)
     * СТАРАЯ ЛОГИКА - НЕ МЕНЯЕМ, просто вызываем getStories в цикле
     * ДОБАВЛЕН fallback для story attachments
     */
    public function getAllStories($taskGid) {
        log_info("[ASANA_CLIENT] Getting all stories for task {$taskGid}");
        
        $stories = [];
        $offset = null;
        $pageNum = 1;
        
        do {
            $batch = $this->getStories($taskGid, 50, $offset);
            
            // Добавляем информацию о пагинации
            if (!empty($batch) && isset($batch[0]['next_page'])) {
                $offset = $batch[0]['next_page']['offset'] ?? null;
                unset($batch[0]['next_page']);
            } else {
                $offset = null;
            }
            
            $stories = array_merge($stories, $batch);
            $pageNum++;
            
            if ($offset) {
                usleep(ASANA_REQUEST_DELAY * 1000000);
            }
        } while ($offset);
        
        log_info("[ASANA_CLIENT] Found " . count($stories) . " stories for task {$taskGid}");
        
        // ========== НОВОЕ: Fallback для story attachments ==========
        // Для stories типа 'attachment' attachments могут быть не вложены, нужен отдельный запрос
        $storiesToFix = [];
        foreach ($stories as $idx => $story) {
            if (($story['resource_subtype'] ?? '') === 'attachment' && empty($story['attachments'])) {
                $storiesToFix[] = $idx;
                log_debug("[ASANA_CLIENT] Story {$story['gid']} needs separate attachments fetch");
            }
        }
        
        if (!empty($storiesToFix)) {
            log_info("[ASANA_CLIENT] Fetching attachments for " . count($storiesToFix) . " stories");
            
            foreach ($storiesToFix as $idx) {
                $storyGid = $stories[$idx]['gid'];
                try {
                    $attachments = $this->getStoryAttachments($storyGid);
                    $stories[$idx]['attachments'] = $attachments;
                    log_debug("[ASANA_CLIENT] Fetched " . count($attachments) . " attachments for story {$storyGid}");
                } catch (Exception $e) {
                    log_warning("[ASANA_CLIENT] Failed to fetch attachments for story {$storyGid}: " . $e->getMessage());
                    $stories[$idx]['attachments'] = [];
                }
                usleep(ASANA_REQUEST_DELAY * 1000000);
            }
        }
        
        // Подсчёт вложений для статистики
        $totalAttachments = 0;
        $storiesWithAttachments = 0;
        foreach ($stories as $story) {
            if (!empty($story['attachments'])) {
                $storiesWithAttachments++;
                $totalAttachments += count($story['attachments']);
            }
        }
        log_info("[ASANA_CLIENT] Stories: " . count($stories) . ", with attachments: {$storiesWithAttachments}, total attachments: {$totalAttachments}");
        
        return $stories;
    }
    
    /**
     * НОВЫЙ МЕТОД: Получение вложений сторис (через отдельный endpoint)
     * Нужен для fallback, когда attachments не приходят в основном запросе
     */
    public function getStoryAttachments($storyGid) {
        $params = ['opt_fields' => 'gid,name,download_url,view_url,size,parent,created_at'];
        $result = $this->request("/stories/{$storyGid}/attachments", 'GET', null, $params);
        return $result['data'] ?? [];
    }
    
    /**
     * Получение прямых вложений задачи
     * СТАРАЯ ЛОГИКА - используем как есть
     */
    public function getAttachments($taskGid) {
        $params = [
            'opt_fields' => 'gid,name,download_url,view_url,size,created_at,resource_subtype'
        ];
        $result = $this->request("/tasks/{$taskGid}/attachments", 'GET', null, $params);
        return $result['data'] ?? [];
    }
    
    /**
     * НОВЫЙ МЕТОД: Получение деталей вложения по GID
     * Используется когда в attachment нет download_url
     */
    public function getAttachment($attachmentGid) {
        $params = [
            'opt_fields' => 'gid,name,download_url,view_url,size,parent,created_at,resource_subtype'
        ];
        $result = $this->request("/attachments/{$attachmentGid}", 'GET', null, $params);
        return $result['data'] ?? null;
    }
    
    /**
     * Получение пользователей
     */
    public function getUsers() {
        $params = [
            'workspace' => $this->workspaceGid,
            'opt_fields' => 'gid,name,email,photo'
        ];
        $result = $this->request('/users', 'GET', null, $params);
        return $result['data'] ?? [];
    }
}