<?php
session_start();
require_once '../includes/db.php';

if (!isset($_SESSION['user_id']) || !isset($_GET['user_id'])) {
    header('HTTP/1.1 403 Forbidden');
    exit;
}

$user_id = intval($_GET['user_id']);
$data = ['labels' => [], 'userChurnRates' => [], 'nicheAverages' => []];

// Get user's streams and their churn rates
$user_streams = $pdo->prepare("
    SELECT s.id, s.name, s.niche_id, 
           COUNT(c.id) AS total_contacts,
           COUNT(ch.id) AS churned_count
    FROM streams s
    LEFT JOIN contacts c ON s.id = c.stream_id
    LEFT JOIN churned_users ch ON c.id = ch.contact_id
    WHERE s.user_id = ?
    GROUP BY s.id, s.name, s.niche_id
");
$user_streams->execute([$user_id]);

while ($stream = $user_streams->fetch()) {
    $data['labels'][] = $stream['name'];
    $user_churn_rate = $stream['total_contacts'] > 0 ? 
        ($stream['churned_count'] / $stream['total_contacts']) * 100 : 0;
    $data['userChurnRates'][] = $user_churn_rate;
    
    // Get niche average for this stream
    $niche_avg = $pdo->prepare("
        SELECT AVG(churn_rate) AS niche_avg
        FROM (
            SELECT (COUNT(ch.id) / NULLIF(COUNT(c.id), 0) * 100 AS churn_rate
            FROM streams s
            LEFT JOIN contacts c ON s.id = c.stream_id
            LEFT JOIN churned_users ch ON c.id = ch.contact_id
            WHERE s.niche_id = ? AND s.user_id != ?
            GROUP BY s.id
        ) AS niche_rates
    ");
    $niche_avg->execute([$stream['niche_id'], $user_id]);
    $avg = $niche_avg->fetchColumn();
    $data['nicheAverages'][] = $avg ? round($avg, 2) : 0;
}

header('Content-Type: application/json');
echo json_encode($data);
?>