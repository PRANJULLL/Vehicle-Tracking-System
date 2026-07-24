<?php
/**
 * GET /api/search.php?query=...&class=...
 * Real-time autocomplete endpoint for student search.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$query = isset($_GET['query']) ? trim((string) $_GET['query']) : '';
$class = isset($_GET['class']) ? trim((string) $_GET['class']) : '';

if ($query === '') {
    echo json_encode(['students' => []]);
    exit;
}

$studentsPath = __DIR__ . '/../src/students.json';
$students = json_decode(file_get_contents($studentsPath), true) ?? [];

$matches = [];
$qLower = strtolower($query);
$cLower = strtolower($class);

foreach ($students as $id => $s) {
    $nameLower  = strtolower($s['name']);
    $classLower = strtolower($s['class'] ?? '');
    $idLower    = strtolower($id);

    $matchesId   = ($idLower === $qLower || str_starts_with($idLower, $qLower));
    $matchesName = (strpos($nameLower, $qLower) !== false);

    if ($matchesId || $matchesName) {
        if ($cLower !== '' && $cLower !== 'all') {
            if ($classLower !== $cLower) {
                continue;
            }
        }

        $matches[] = [
            'id'      => $id,
            'name'    => $s['name'],
            'class'   => $s['class']   ?? '',
            'address' => $s['address'] ?? '',
            'stop'    => $s['stop']    ?? '',
        ];

        if (count($matches) >= 12) {
            break;
        }
    }
}

echo json_encode(['students' => $matches]);
