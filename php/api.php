<?php
// php/api.php — JSON API for the AccessLens front end
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/audit_engine.php';
require_once __DIR__ . '/url_guard.php';

// Send JSON back and stop
function respond($data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data);
    exit;
}




// Work out which route was called, e.g. "/runs/1" becomes ['runs', '1']
$path   = trim($_SERVER['PATH_INFO'] ?? '', '/');
$parts  = $path === '' ? [] : explode('/', $path);
$route  = $parts[0] ?? '';
$id     = isset($parts[1]) ? (int)$parts[1] : 0;
$method = $_SERVER['REQUEST_METHOD'];
$db     = getDB();

// GET rules: the WCAG catalogue
if ($method === 'GET' && $route === 'rules') {
    $rows = $db->query(
        'SELECT rule_code, rule_name, principle, level, description, how_to_fix
         FROM wcag_rules ORDER BY id'
    )->fetchAll();
    respond($rows);
}

// GET runs/{projectId}: all audits for a project, newest first
if ($method === 'GET' && $route === 'runs') {
    $stmt = $db->prepare(
        'SELECT r.*, p.name AS project_name
         FROM audit_runs r
         JOIN projects p ON p.id = r.project_id
         WHERE r.project_id = ?
         ORDER BY r.created_at DESC
         LIMIT 50'
    );
    $stmt->execute([$id]);
    respond($stmt->fetchAll());
}

// GET trends/{projectId}: score history, oldest first for the chart
if ($method === 'GET' && $route === 'trends') {
    $stmt = $db->prepare(
        'SELECT score, critical_count, serious_count, recorded_at
         FROM score_trends
         WHERE project_id = ?
         ORDER BY recorded_at ASC'
    );
    $stmt->execute([$id]);
    respond($stmt->fetchAll());
}

// GET report/{runId}: one run, its issues and its project's trend
if ($method === 'GET' && $route === 'report') {
    $stmt = $db->prepare(
        'SELECT r.*, p.name AS project_name
         FROM audit_runs r
         JOIN projects p ON p.id = r.project_id
         WHERE r.id = ?'
    );
    $stmt->execute([$id]);
    $run = $stmt->fetch();
    if (!$run) respond(['error' => 'Run not found'], 404);

    $stmt = $db->prepare(
        "SELECT w.rule_code, w.rule_name, w.principle, w.level,
                i.severity, i.element_type, i.element_html, i.selector,
                i.context, i.fix_suggestion
         FROM audit_issues i
         JOIN wcag_rules w ON w.id = i.rule_id
         WHERE i.run_id = ?
         ORDER BY FIELD(i.severity, 'critical', 'serious', 'moderate', 'minor')"
    );
    $stmt->execute([$id]);
    $issues = $stmt->fetchAll();

        // Trend for this website only, so different sites don't mix in one chart
    $stmt = $db->prepare(
        'SELECT t.score, t.critical_count, t.serious_count, t.recorded_at
         FROM score_trends t
         JOIN audit_runs r ON r.id = t.run_id
         WHERE r.url_audited = ?
         ORDER BY t.recorded_at ASC'
    );
    $stmt->execute([$run['url_audited']]);

    respond(['run' => $run, 'issues' => $issues, 'trends' => $stmt->fetchAll()]);
}

// POST audit: check the URL is safe, then run the engine
if ($method === 'POST' && $route === 'audit') {
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    $url  = trim($body['url'] ?? '');

    if ($url === '' || strlen($url) > 500 || !isSafePublicUrl($url)) {
        respond([
            'success' => false,
            'error'   => 'Please enter a public website address starting with http:// or https://'
        ], 400);
    }

    $engine = new AuditEngine();
    $result = $engine->run(1, 1, $url);   // demo user 1, default project 1
    respond($result, $result['success'] ? 200 : 422);
}

// Anything else
respond(['error' => 'Not found'], 404);