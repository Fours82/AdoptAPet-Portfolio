<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';

function requireAnalyticsAdministrator(PDO $pdo): int
{
    return requireOperationalAdministrator($pdo);
}

function analyticsGroupedCounts(PDO $pdo, string $sql, string $statusColumn, array $expectedStatuses): array
{
    $counts = array_fill_keys($expectedStatuses, 0);
    $statement = $pdo->prepare($sql);
    $statement->execute();

    foreach ($statement->fetchAll() as $row) {
        $status = (string) $row[$statusColumn];
        if (array_key_exists($status, $counts)) {
            $counts[$status] = (int) $row['total'];
        }
    }

    return $counts;
}

function analyticsMonthlyTemplate(int $months, DateTimeImmutable $firstMonth): array
{
    $series = [];

    for ($index = $months - 1; $index >= 0; $index--) {
        $month = $firstMonth->modify('-' . $index . ' months');
        $key = $month->format('Y-m');
        $series[$key] = [
            'month' => $key,
            'label' => $month->format('M Y'),
        ];
    }

    return $series;
}

function analyticsMonthlyQuery(PDO $pdo, string $sql, string $startDate, string $endDate): array
{
    $statement = $pdo->prepare($sql);
    $statement->execute(['start_date' => $startDate, 'end_date' => $endDate]);

    $rows = [];
    foreach ($statement->fetchAll() as $row) {
        $rows[(string) $row['month']] = $row;
    }

    return $rows;
}

try {
    $pdo = getDatabaseConnection();
    requireAnalyticsAdministrator($pdo);

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
        exit;
    }

    $monthsInput = $_GET['months'] ?? '6';
    if (!is_string($monthsInput) || !ctype_digit($monthsInput) || !in_array((int) $monthsInput, [3, 6, 12], true)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Select a reporting period of 3, 6, or 12 months.']);
        exit;
    }
    $months = (int) $monthsInput;

    $summaryStatement = $pdo->prepare(
        'SELECT
            (SELECT COUNT(*) FROM adoption_applications) AS total_applications,
            (SELECT COUNT(*) FROM adoption_applications WHERE application_status = \'approved\') AS successful_adoptions,
            (SELECT COUNT(*) FROM pets) AS pet_listings,
            (SELECT COUNT(*) FROM pets WHERE adoption_status = \'available\') AS available_pets,
            (SELECT COUNT(*) FROM pets WHERE adoption_status = \'adopted\') AS adopted_pets,
            (SELECT COUNT(*) FROM stray_reports) AS stray_reports,
            (SELECT COUNT(*) FROM donations WHERE donation_status = \'completed\') AS completed_donations,
            (SELECT COALESCE(SUM(amount), 0) FROM donations WHERE donation_status = \'completed\') AS donation_total,
            (SELECT COUNT(*) FROM users WHERE role = \'registered_user\' AND account_status = \'active\' AND deleted_at IS NULL) AS active_registered_users,
            (SELECT COUNT(*) FROM community_posts WHERE post_status = \'published\') AS community_posts,
            (SELECT COUNT(*) FROM news_posts WHERE publication_status = \'published\') AS published_news'
    );
    $summaryStatement->execute();
    $rawSummary = $summaryStatement->fetch();

    $totalApplications = (int) $rawSummary['total_applications'];
    $successfulAdoptions = (int) $rawSummary['successful_adoptions'];
    $summary = [
        'total_applications' => $totalApplications,
        'successful_adoptions' => $successfulAdoptions,
        'application_approval_rate' => $totalApplications > 0
            ? round(($successfulAdoptions / $totalApplications) * 100, 1)
            : 0.0,
        'pet_listings' => (int) $rawSummary['pet_listings'],
        'available_pets' => (int) $rawSummary['available_pets'],
        'adopted_pets' => (int) $rawSummary['adopted_pets'],
        'stray_reports' => (int) $rawSummary['stray_reports'],
        'completed_donations' => (int) $rawSummary['completed_donations'],
        'donation_total' => (float) $rawSummary['donation_total'],
        'active_registered_users' => (int) $rawSummary['active_registered_users'],
        'community_posts' => (int) $rawSummary['community_posts'],
        'published_news' => (int) $rawSummary['published_news'],
    ];

    $breakdowns = [
        'applications' => analyticsGroupedCounts(
            $pdo,
            'SELECT application_status, COUNT(*) AS total FROM adoption_applications GROUP BY application_status',
            'application_status',
            ['pending', 'approved', 'rejected', 'withdrawn', 'cancelled']
        ),
        'pets' => analyticsGroupedCounts(
            $pdo,
            'SELECT adoption_status, COUNT(*) AS total FROM pets GROUP BY adoption_status',
            'adoption_status',
            ['available', 'pending', 'adopted', 'inactive']
        ),
        'stray_reports' => analyticsGroupedCounts(
            $pdo,
            'SELECT report_status, COUNT(*) AS total FROM stray_reports GROUP BY report_status',
            'report_status',
            ['submitted', 'under_review', 'assigned', 'resolved', 'closed']
        ),
        'donations' => analyticsGroupedCounts(
            $pdo,
            'SELECT donation_status, COUNT(*) AS total FROM donations GROUP BY donation_status',
            'donation_status',
            ['pending', 'completed', 'failed', 'refunded']
        ),
    ];

    $firstMonth = new DateTimeImmutable('first day of this month 00:00:00');
    $rangeStart = $firstMonth->modify('-' . ($months - 1) . ' months');
    $rangeEnd = $firstMonth->modify('+1 month');
    $startDate = $rangeStart->format('Y-m-d H:i:s');
    $endDate = $rangeEnd->format('Y-m-d H:i:s');
    $template = analyticsMonthlyTemplate($months, $firstMonth);

    $applicationRows = analyticsMonthlyQuery(
        $pdo,
        'SELECT DATE_FORMAT(submitted_at, \'%Y-%m\') AS month,
                COUNT(*) AS total,
                SUM(application_status = \'approved\') AS successful
         FROM adoption_applications
         WHERE submitted_at >= :start_date AND submitted_at < :end_date
         GROUP BY DATE_FORMAT(submitted_at, \'%Y-%m\')',
        $startDate,
        $endDate
    );
    $donationRows = analyticsMonthlyQuery(
        $pdo,
        'SELECT DATE_FORMAT(COALESCE(donated_at, created_at), \'%Y-%m\') AS month,
                COUNT(*) AS contributions,
                COALESCE(SUM(amount), 0) AS total_amount
         FROM donations
         WHERE donation_status = \'completed\'
           AND COALESCE(donated_at, created_at) >= :start_date
           AND COALESCE(donated_at, created_at) < :end_date
         GROUP BY DATE_FORMAT(COALESCE(donated_at, created_at), \'%Y-%m\')',
        $startDate,
        $endDate
    );
    $userRows = analyticsMonthlyQuery(
        $pdo,
        'SELECT DATE_FORMAT(created_at, \'%Y-%m\') AS month, COUNT(*) AS registrations
         FROM users
         WHERE role = \'registered_user\'
           AND deleted_at IS NULL
           AND created_at >= :start_date AND created_at < :end_date
         GROUP BY DATE_FORMAT(created_at, \'%Y-%m\')',
        $startDate,
        $endDate
    );
    $strayRows = analyticsMonthlyQuery(
        $pdo,
        'SELECT DATE_FORMAT(submitted_at, \'%Y-%m\') AS month, COUNT(*) AS reports
         FROM stray_reports
         WHERE submitted_at >= :start_date AND submitted_at < :end_date
         GROUP BY DATE_FORMAT(submitted_at, \'%Y-%m\')',
        $startDate,
        $endDate
    );

    $applicationTrend = [];
    $donationTrend = [];
    $userTrend = [];
    $strayTrend = [];

    foreach ($template as $key => $month) {
        $applicationTrend[] = $month + [
            'total' => isset($applicationRows[$key]) ? (int) $applicationRows[$key]['total'] : 0,
            'successful' => isset($applicationRows[$key]) ? (int) $applicationRows[$key]['successful'] : 0,
        ];
        $donationTrend[] = $month + [
            'contributions' => isset($donationRows[$key]) ? (int) $donationRows[$key]['contributions'] : 0,
            'total_amount' => isset($donationRows[$key]) ? (float) $donationRows[$key]['total_amount'] : 0.0,
        ];
        $userTrend[] = $month + [
            'registrations' => isset($userRows[$key]) ? (int) $userRows[$key]['registrations'] : 0,
        ];
        $strayTrend[] = $month + [
            'reports' => isset($strayRows[$key]) ? (int) $strayRows[$key]['reports'] : 0,
        ];
    }

    echo json_encode([
        'success' => true,
        'period' => [
            'months' => $months,
            'from' => $rangeStart->format('Y-m-d'),
            'to' => $rangeEnd->modify('-1 day')->format('Y-m-d'),
        ],
        'summary' => $summary,
        'breakdowns' => $breakdowns,
        'trends' => [
            'applications' => $applicationTrend,
            'donations' => $donationTrend,
            'registered_users' => $userTrend,
            'stray_reports' => $strayTrend,
        ],
        'generated_at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
    ]);
} catch (PDOException $exception) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Unable to load analytics. Please try again later.']);
}
