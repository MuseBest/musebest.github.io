<?php
// subscribe.php — EarnQuill newsletter signup for the static site.
//
// The live site is a static export with no server API, so this small
// endpoint receives the subscribe form, emails the new address to
// hello@earnquill.com, and keeps a backup list in a CSV file one level
// above public_html (not reachable from the web). No secrets here: the
// recipient is the site's public contact address.
//
// Behaviour:
// - POST with an `email` field (form post or JSON) -> validates it,
//   skips duplicates, emails hello@earnquill.com, returns {"ok":true}.
// - Anything else -> JSON error with the matching HTTP status.
// - The email is the primary channel: if the CSV backup cannot be
//   written, the signup still succeeds as long as the email is sent.
header('Content-Type: application/json');

$TO = 'hello@earnquill.com';
$STORE = dirname(__DIR__) . '/subscribers.csv'; // one level above public_html

function respond($code, $ok, $error = '') {
  http_response_code($code);
  echo json_encode(array('ok' => $ok, 'error' => $error));
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  respond(405, false, 'POST only');
}

// Accept a normal form post, and JSON as well.
$email = '';
if (!empty($_POST['email'])) {
  $email = trim((string) $_POST['email']);
} else {
  $raw = file_get_contents('php://input');
  if ($raw) {
    $data = json_decode($raw, true);
    if (is_array($data) && !empty($data['email'])) {
      $email = trim((string) $data['email']);
    }
  }
}
// Never let a newline reach a mail header.
$email = str_replace(array("\r", "\n"), '', $email);
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  respond(400, false, 'Please enter a valid email address.');
}

// Dedupe against the backup list, and append new addresses to it.
// Best effort only: if the list cannot be read or written, the signup
// still goes through by email.
$already = false;
if (is_file($STORE)) {
  $lines = @file($STORE, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if ($lines) {
    foreach ($lines as $line) {
      $parts = str_getcsv($line);
      if (isset($parts[0]) && strtolower(trim($parts[0])) === strtolower($email)) {
        $already = true;
        break;
      }
    }
  }
}
if (!$already) {
  @file_put_contents(
    $STORE,
    '"' . str_replace('"', '""', $email) . '","' . date('Y-m-d H:i:s') . '"' . "\n",
    FILE_APPEND | LOCK_EX
  );
}
if ($already) {
  respond(200, true); // already on the list; do not email twice
}

$subject = 'New EarnQuill subscriber: ' . $email;
$message = "A new reader subscribed to EarnQuill.\n\nEmail: $email\nDate: " . date('Y-m-d H:i:s') . "\n";
$headers = "From: EarnQuill <hello@earnquill.com>\r\n"
  . "Reply-To: $email\r\n"
  . "Content-Type: text/plain; charset=UTF-8\r\n";

if (!mail($TO, $subject, $message, $headers)) {
  error_log('EarnQuill subscribe: mail() failed for ' . $email);
  respond(500, false, 'Could not send right now. Please try again later.');
}
respond(200, true);
