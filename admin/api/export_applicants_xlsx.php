<?php
declare(strict_types=1);

require __DIR__ . '/../config.php';
require __DIR__ . '/../db.php';

if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

if (empty($_SESSION['admin_logged_in']) || (int)$_SESSION['admin_logged_in'] !== 1) {
  http_response_code(401);
  exit('Unauthorized');
}

$campId = (int)($_GET['campId'] ?? 0);
$status = trim((string)($_GET['status'] ?? ''));
$q      = trim((string)($_GET['q'] ?? ''));

if ($campId <= 0) { http_response_code(400); exit('Bad campId'); }

/* ------------------ COMPOSER AUTOLOAD (fallback) ------------------ */
$autoload1 = __DIR__ . '/../vendor/autoload.php';        // /admin/vendor/autoload.php
$autoload2 = __DIR__ . '/../../vendor/autoload.php';     // /youthagency/vendor/autoload.php

if (is_file($autoload1)) require $autoload1;
elseif (is_file($autoload2)) require $autoload2;
else { http_response_code(500); exit('Composer autoload.php not found'); }

/* ------------------ PHP 7 COMPAT: str_contains ------------------ */
if (!function_exists('str_contains')) {
  function str_contains(string $haystack, string $needle): bool {
    return $needle === '' || strpos($haystack, $needle) !== false;
  }
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

function asArrayValues($values): array {
  if (!$values) return [];
  if (is_string($values)) {
    $j = json_decode($values, true);
    if (json_last_error() === JSON_ERROR_NONE) $values = $j;
    else return [$values];
  }
  if (is_array($values)) {
    $isAssoc = array_keys($values) !== range(0, count($values) - 1);
    return $isAssoc ? array_values($values) : $values;
  }
  return [$values];
}

try {
  /* ------------------- LOAD FIELDS ------------------- */
  $fs = $pdo->prepare("SELECT id,label FROM camps_fields WHERE camp_id=? ORDER BY sort_order ASC, id ASC");
  $fs->execute([$campId]);
  $fields = $fs->fetchAll(PDO::FETCH_ASSOC);

  /* ------------------- LOAD ROWS ------------------- */
  $where = ["camp_id=?"];
  $args = [$campId];

  if ($status !== '' && in_array($status, ['pending','approved','rejected'], true)) {
    $where[] = "status=?";
    $args[] = $status;
  }

  $sql = "SELECT id,created_at,unique_key,status,admin_note,values_json
          FROM camps_registrations
          WHERE ".implode(" AND ", $where)."
          ORDER BY id DESC";
  $st = $pdo->prepare($sql);
  $st->execute($args);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  // optional search (q) inside unique_key + values_json
  if ($q !== '') {
    $qq = mb_strtolower($q, 'UTF-8');
    $rows = array_values(array_filter($rows, function($r) use ($qq){
      $u = mb_strtolower((string)($r['unique_key'] ?? ''), 'UTF-8');
      $v = mb_strtolower((string)($r['values_json'] ?? ''), 'UTF-8');
      return str_contains($u, $qq) || str_contains($v, $qq);
    }));
  }

  /* ------------------- BUILD XLSX ------------------- */
  $spreadsheet = new Spreadsheet();
  $sheet = $spreadsheet->getActiveSheet();
  $sheet->setTitle("Applicants");

  $headers = ["ID","Created","Unique","Status","Note"];
  foreach ($fields as $f) $headers[] = (string)$f['label'];

  $sheet->fromArray($headers, null, 'A1');

  $r = 2;
  foreach ($rows as $row) {
    $vals = asArrayValues($row['values_json'] ?? '');

    $base = [
      $row['id'] ?? '',
      $row['created_at'] ?? '',
      $row['unique_key'] ?? '',
      $row['status'] ?? '',
      $row['admin_note'] ?? '',
    ];

    $extra = [];
    for ($i=0; $i<count($fields); $i++) $extra[] = isset($vals[$i]) ? (string)$vals[$i] : '';

    $sheet->fromArray(array_merge($base, $extra), null, 'A'.$r);
    $r++;
  }

  // (Optional) autosize can be slow on huge sheets, but ok for <= 1000 rows
  $highestColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());
  for ($i = 1; $i <= $highestColIndex; $i++) {
    $col = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
    $sheet->getColumnDimension($col)->setAutoSize(true);
  }

  $filename = "applicants_{$campId}_" . date("Ymd_His") . ".xlsx";
  header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  header('Content-Disposition: attachment; filename="'.$filename.'"');
  header('Cache-Control: max-age=0');

  $writer = new Xlsx($spreadsheet);
  $writer->save('php://output');
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo "Export error: " . $e->getMessage();
  exit;
}