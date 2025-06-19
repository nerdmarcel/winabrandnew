<?php
declare(strict_types=1);

/**
 * File: controllers/AdminDiscountController.php
 * Location: controllers/AdminDiscountController.php
 *
 * WinABN Admin Discount Management Controller
 *
 * Handles admin interface for managing discounts, referral campaigns,
 * and promotional activities with comprehensive analytics and controls.
 *
 * @package WinABN\Controllers
 * @author WinABN Development Team
 * @version 1.0
 */

namespace WinABN\Controllers;

use WinABN\Core\Controller;
use WinABN\Core\Security;
use WinABN\Core\Database;
use WinABN\Models\UserAction;
use WinABN\Core\ReferralTracker;
use WinABN\Core\FraudPrevention;
use Exception;

class AdminDiscountController extends Controller
{
    /**
     * UserAction model instance
     *
     * @var UserAction
     */
    private UserAction $userActionModel;

    /**
     * ReferralTracker instance
     *
     * @var ReferralTracker
     */
    private ReferralTracker $referralTracker;

    /**
     * Constructor
     */
    public function __construct()
    {
        parent::__construct();
        $this->requireAdminAuth();
        $this->userActionModel = new UserAction();
        $this->referralTracker = new ReferralTracker();
    }

    /**
     * Display discount management dashboard
     *
     * @return void
     */
    public function index(): void
    {
        try {
            $stats = $this->getDiscountDashboardStats();
            $recentDiscounts = $this->getRecentDiscounts(20);
            $topReferrers = $this->getTopReferrers(10);
            $fraudStats = FraudPrevention::getFraudStatistics(30);

            $this->view->render('admin/discounts/dashboard', [
                'title' => 'Discount Management Dashboard',
                'stats' => $stats,
                'recent_discounts' => $recentDiscounts,
                'top_referrers' => $topReferrers,
                'fraud_stats' => $fraudStats
            ]);

        } catch (Exception $e) {
            $this->logError('Admin discount dashboard failed', ['error' => $e->getMessage()]);
            $this->redirect('/adminportal/dashboard?error=dashboard_failed');
        }
    }

    /**
     * Display discount list with filtering
     *
     * @return void
     */
    public function list(): void
    {
        try {
            $filters = $this->getDiscountFilters();
            $discounts = $this->getFilteredDiscounts($filters);
            $pagination = $this->calculatePagination($discounts['total'], $filters['per_page'], $filters['page']);

            $this->view->render('admin/discounts/list', [
                'title' => 'All Discounts',
                'discounts' => $discounts['data'],
                'filters' => $filters,
                'pagination' => $pagination,
                'total_count' => $discounts['total']
            ]);

        } catch (Exception $e) {
            $this->logError('Admin discount list failed', ['error' => $e->getMessage()]);
            $this->redirect('/adminportal/discounts?error=list_failed');
        }
    }

    /**
     * Create manual discount form
     *
     * @return void
     */
    public function create(): void
    {
        if ($this->isPost()) {
            $this->handleCreateDiscount();
            return;
        }

        $this->view->render('admin/discounts/create', [
            'title' => 'Create Manual Discount',
            'discount_types' => $this->getDiscountTypes(),
            'csrf_token' => Security::generateCsrfToken()
        ]);
    }

    /**
     * Handle discount creation
     *
     * @return void
     */
    private function handleCreateDiscount(): void
    {
        try {
            if (!$this->validateCsrf()) {
                throw new Exception('Invalid CSRF token');
            }

            $data = $this->validateDiscountCreationData();

            // Create discount action
            $discountData = [
                'email' => $data['email'],
                'action_type' => $data['type'],
                'discount_amount' => $data['amount'],
                'discount_code' => $data['code'] ?? null,
                'expires_at' => $data['expires_at'] ?? null,
                'is_active' => true
            ];

            $actionId = $this->userActionModel->create($discountData);

            // Log admin action
            $this->logAdminAction('discount_created', [
                'action_id' => $actionId,
                'discount_data' => $discountData,
                'admin_user' => $this->getCurrentAdminUser()['username']
            ]);

            $this->setFlashMessage('success', 'Discount created successfully');
            $this->redirect('/adminportal/discounts');

        } catch (Exception $e) {
            $this->logError('Admin discount creation failed', [
                'error' => $e->getMessage(),
                'post_data' => $_POST
            ]);

            $this->setFlashMessage('error', 'Failed to create discount: ' . $e->getMessage());
            $this->redirect('/adminportal/discounts/create');
        }
    }

    /**
     * Bulk discount management
     *
     * @return void
     */
    public function bulkAction(): void
    {
        try {
            if (!$this->validateCsrf()) {
                throw new Exception('Invalid CSRF token');
            }

            $action = $_POST['bulk_action'] ?? '';
            $discountIds = $_POST['discount_ids'] ?? [];

            if (empty($action) || empty($discountIds)) {
                throw new Exception('Invalid bulk action parameters');
            }

            $results = $this->processBulkAction($action, $discountIds);

            $this->jsonResponse([
                'success' => true,
                'message' => "Bulk action '{$action}' completed",
                'results' => $results
            ]);

        } catch (Exception $e) {
            $this->logError('Bulk discount action failed', [
                'error' => $e->getMessage(),
                'action' => $_POST['bulk_action'] ?? '',
                'ids' => $_POST['discount_ids'] ?? []
            ]);

            $this->jsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Referral analytics dashboard
     *
     * @return void
     */
    public function referralAnalytics(): void
    {
        try {
            $timeframe = $_GET['timeframe'] ?? '30';
            $analytics = $this->getReferralAnalytics((int) $timeframe);

            $this->view->render('admin/discounts/referral-analytics', [
                'title' => 'Referral Analytics',
                'analytics' => $analytics,
                'timeframe' => $timeframe,
                'timeframe_options' => [
                    '7' => 'Last 7 days',
                    '30' => 'Last 30 days',
                    '90' => 'Last 90 days',
                    '365' => 'Last year'
                ]
            ]);

        } catch (Exception $e) {
            $this->logError('Referral analytics failed', ['error' => $e->getMessage()]);
            $this->redirect('/adminportal/discounts?error=analytics_failed');
        }
    }

    /**
     * Fraud detection dashboard
     *
     * @return void
     */
    public function fraudDetection(): void
    {
        try {
            $timeframe = $_GET['timeframe'] ?? '30';
            $fraudData = $this->getFraudDetectionData((int) $timeframe);

            $this->view->render('admin/discounts/fraud-detection', [
                'title' => 'Fraud Detection',
                'fraud_data' => $fraudData,
                'timeframe' => $timeframe,
                'blocked_ips' => $this->getBlockedIPs(),
                'suspicious_patterns' => $this->getSuspiciousPatterns()
            ]);

        } catch (Exception $e) {
            $this->logError('Fraud detection dashboard failed', ['error' => $e->getMessage()]);
            $this->redirect('/adminportal/discounts?error=fraud_failed');
        }
    }

    /**
     * Export discount data
     *
     * @return void
     */
    public function export(): void
    {
        try {
            $format = $_GET['format'] ?? 'csv';
            $filters = $this->getDiscountFilters();

            $data = $this->getExportData($filters);

            switch ($format) {
                case 'csv':
                    $this->exportToCsv($data);
                    break;
                case 'xlsx':
                    $this->exportToXlsx($data);
                    break;
                default:
                    throw new Exception('Unsupported export format');
            }

        } catch (Exception $e) {
            $this->logError('Discount export failed', [
                'error' => $e->getMessage(),
                'format' => $_GET['format'] ?? '',
                'filters' => $_GET
            ]);

            $this->setFlashMessage('error', 'Export failed: ' . $e->getMessage());
            $this->redirect('/adminportal/discounts');
        }
    }

    /**
     * Get discount dashboard statistics
     *
     * @return array<string, mixed>
     */
    private function getDiscountDashboardStats(): array
    {
        $stats = [];

        // Total discounts by type (last 30 days)
        $typeStatsQuery = "
            SELECT
                action_type,
                COUNT(*) as count,
                COUNT(CASE WHEN used_at IS NOT NULL THEN 1 END) as used_count,
                AVG(discount_amount) as avg_amount
            FROM user_actions
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY action_type
        ";
        $stats['by_type'] = Database::fetchAll($typeStatsQuery);

        // Usage statistics
        $usageStatsQuery = "
            SELECT
                COUNT(*) as total_created,
                COUNT(CASE WHEN used_at IS NOT NULL THEN 1 END) as total_used,
                COUNT(CASE WHEN expires_at < NOW() THEN 1 END) as expired,
                COUNT(CASE WHEN is_active = 1 AND expires_at > NOW() THEN 1 END) as active
            FROM user_actions
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ";
        $stats['usage'] = Database::fetchOne($usageStatsQuery);

        // Revenue impact
        $revenueQuery = "
            SELECT
                COUNT(ua.id) as discounts_applied,
                SUM(p.payment_amount) as gross_revenue,
                SUM(p.payment_amount * (ua.discount_amount / 100)) as total_discount_amount,
                AVG(ua.discount_amount) as avg_discount_percentage
            FROM user_actions ua
            JOIN participants p ON ua.applied_to_participant_id = p.id
            WHERE ua.used_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            AND p.payment_status = 'paid'
        ";
        $stats['revenue_impact'] = Database::fetchOne($revenueQuery);

        // Referral performance
        $referralQuery = "
            SELECT
                COUNT(DISTINCT ct.source_id) as unique_referrers,
                COUNT(ct.id) as total_referrals,
                COUNT(CASE WHEN p.payment_status = 'paid' THEN 1 END) as successful_conversions,
                AVG(p.payment_amount) as avg_conversion_value
            FROM conversion_tracking ct
            JOIN participants p ON ct.target_participant_id = p.id
            WHERE ct.source_type = 'referral'
            AND ct.converted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        ";
        $stats['referral_performance'] = Database::fetchOne($referralQuery);

        return $stats;
    }

    /**
     * Get recent discount activities
     *
     * @param int $limit Number of records to return
     * @return array<array<string, mixed>>
     */
    private function getRecentDiscounts(int $limit): array
    {
        $query = "
            SELECT
                ua.*,
                p.first_name,
                p.last_name,
                p.payment_amount,
                g.name as game_name
            FROM user_actions ua
            LEFT JOIN participants p ON ua.applied_to_participant_id = p.id
            LEFT JOIN rounds r ON p.round_id = r.id
            LEFT JOIN games g ON r.game_id = g.id
            ORDER BY ua.created_at DESC
            LIMIT ?
        ";

        return Database::fetchAll($query, [$limit]);
    }

    /**
     * Get top referrers by conversion count
     *
     * @param int $limit Number of referrers to return
     * @return array<array<string, mixed>>
     */
    private function getTopReferrers(int $limit): array
    {
        $query = "
            SELECT
                ct.source_id as referrer_email,
                COUNT(ct.id) as total_referrals,
                COUNT(CASE WHEN p.payment_status = 'paid' THEN 1 END) as successful_conversions,
                SUM(CASE WHEN p.payment_status = 'paid' THEN p.payment_amount ELSE 0 END) as total_revenue,
                ROUND((COUNT(CASE WHEN p.payment_status = 'paid' THEN 1 END) / COUNT(ct.id)) * 100, 2) as conversion_rate
            FROM conversion_tracking ct
            JOIN participants p ON ct.target_participant_id = p.id
            WHERE ct.source_type = 'referral'
            AND ct.converted_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY ct.source_id
            ORDER BY successful_conversions DESC, total_referrals DESC
            LIMIT ?
        ";

        return Database::fetchAll($query, [$limit]);
    }

    /**
     * Get discount filters from request
     *
     * @return array<string, mixed>
     */
    private function getDiscountFilters(): array
    {
        return [
            'type' => $_GET['type'] ?? '',
            'status' => $_GET['status'] ?? '',
            'email' => $_GET['email'] ?? '',
            'date_from' => $_GET['date_from'] ?? '',
            'date_to' => $_GET['date_to'] ?? '',
            'page' => max(1, (int) ($_GET['page'] ?? 1)),
            'per_page' => min(100, max(10, (int) ($_GET['per_page'] ?? 25)))
        ];
    }

    /**
     * Get filtered discounts with pagination
     *
     * @param array<string, mixed> $filters Filters
     * @return array<string, mixed>
     */
    private function getFilteredDiscounts(array $filters): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['type'])) {
            $where[] = 'ua.action_type = ?';
            $params[] = $filters['type'];
        }

        if (!empty($filters['status'])) {
            switch ($filters['status']) {
                case 'used':
                    $where[] = 'ua.used_at IS NOT NULL';
                    break;
                case 'active':
                    $where[] = 'ua.is_active = 1 AND ua.used_at IS NULL AND (ua.expires_at IS NULL OR ua.expires_at > NOW())';
                    break;
                case 'expired':
                    $where[] = 'ua.expires_at < NOW()';
                    break;
            }
        }

        if (!empty($filters['email'])) {
            $where[] = 'ua.email LIKE ?';
            $params[] = '%' . $filters['email'] . '%';
        }

        if (!empty($filters['date_from'])) {
            $where[] = 'ua.created_at >= ?';
            $params[] = $filters['date_from'] . ' 00:00:00';
        }

        if (!empty($filters['date_to'])) {
            $where[] = 'ua.created_at <= ?';
            $params[] = $filters['date_to'] . ' 23:59:59';
        }

        $whereClause = implode(' AND ', $where);

        // Get total count
        $countQuery = "
            SELECT COUNT(*) as total
            FROM user_actions ua
            WHERE {$whereClause}
        ";
        $totalResult = Database::fetchOne($countQuery, $params);

        // Get paginated data
        $offset = ($filters['page'] - 1) * $filters['per_page'];
        $dataQuery = "
            SELECT
                ua.*,
                p.first_name,
                p.last_name,
                p.payment_amount,
                g.name as game_name
            FROM user_actions ua
            LEFT JOIN participants p ON ua.applied_to_participant_id = p.id
            LEFT JOIN rounds r ON p.round_id = r.id
            LEFT JOIN games g ON r.game_id = g.id
            WHERE {$whereClause}
            ORDER BY ua.created_at DESC
            LIMIT ? OFFSET ?
        ";

        $dataParams = array_merge($params, [$filters['per_page'], $offset]);
        $data = Database::fetchAll($dataQuery, $dataParams);

        return [
            'data' => $data,
            'total' => (int) $totalResult['total']
        ];
    }

    /**
     * Validate discount creation data
     *
     * @return array<string, mixed>
     * @throws Exception
     */
    private function validateDiscountCreationData(): array
    {
        $required = ['email', 'type', 'amount'];
        $data = $this->validateRequired($required);

        // Validate email
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email format');
        }

        // Validate discount type
        $validTypes = ['replay', 'referral', 'bonus', 'manual'];
        if (!in_array($data['type'], $validTypes)) {
            throw new Exception('Invalid discount type');
        }

        // Validate amount
        $amount = (float) $data['amount'];
        if ($amount <= 0 || $amount > 100) {
            throw new Exception('Discount amount must be between 0 and 100 percent');
        }
        $data['amount'] = $amount;

        // Validate expiry date if provided
        if (!empty($_POST['expires_at'])) {
            $expiryDate = $_POST['expires_at'];
            if (strtotime($expiryDate) < time()) {
                throw new Exception('Expiry date must be in the future');
            }
            $data['expires_at'] = $expiryDate;
        }

        // Generate discount code if requested
        if (!empty($_POST['generate_code'])) {
            $data['code'] = $this->generateDiscountCode();
        }

        return $data;
    }

    /**
     * Process bulk action on discounts
     *
     * @param string $action Action to perform
     * @param array<int> $discountIds Discount IDs
     * @return array<string, mixed>
     */
    private function processBulkAction(string $action, array $discountIds): array
    {
        $results = ['success' => 0, 'failed' => 0, 'errors' => []];

        foreach ($discountIds as $id) {
            try {
                switch ($action) {
                    case 'deactivate':
                        $this->userActionModel->update((int) $id, ['is_active' => false]);
                        break;
                    case 'activate':
                        $this->userActionModel->update((int) $id, ['is_active' => true]);
                        break;
                    case 'extend_expiry':
                        $newExpiry = date('Y-m-d H:i:s', strtotime('+30 days'));
                        $this->userActionModel->update((int) $id, ['expires_at' => $newExpiry]);
                        break;
                    case 'delete':
                        $this->userActionModel->delete((int) $id);
                        break;
                    default:
                        throw new Exception("Unknown action: {$action}");
                }
                $results['success']++;
            } catch (Exception $e) {
                $results['failed']++;
                $results['errors'][] = "ID {$id}: " . $e->getMessage();
            }
        }

        // Log bulk action
        $this->logAdminAction('discount_bulk_action', [
            'action' => $action,
            'discount_ids' => $discountIds,
            'results' => $results,
            'admin_user' => $this->getCurrentAdminUser()['username']
        ]);

        return $results;
    }

    /**
     * Get referral analytics data
     *
     * @param int $days Number of days to analyze
     * @return array<string, mixed>
     */
    private function getReferralAnalytics(int $days): array
    {
        $analytics = [];

        // Daily referral conversions
        $dailyQuery = "
            SELECT
                DATE(ct.converted_at) as date,
                COUNT(ct.id) as total_referrals,
                COUNT(CASE WHEN p.payment_status = 'paid' THEN 1 END) as successful_conversions,
                SUM(CASE WHEN p.payment_status = 'paid' THEN p.payment_amount ELSE 0 END) as revenue
            FROM conversion_tracking ct
            JOIN participants p ON ct.target_participant_id = p.id
            WHERE ct.source_type = 'referral'
            AND ct.converted_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY DATE(ct.converted_at)
            ORDER BY date DESC
        ";
        $analytics['daily_data'] = Database::fetchAll($dailyQuery, [$days]);

        // Top performing games
        $gameQuery = "
            SELECT
                g.name as game_name,
                COUNT(ct.id) as total_referrals,
                COUNT(CASE WHEN p.payment_status = 'paid' THEN 1 END) as successful_conversions,
                ROUND((COUNT(CASE WHEN p.payment_status = 'paid' THEN 1 END) / COUNT(ct.id)) * 100, 2) as conversion_rate
            FROM conversion_tracking ct
            JOIN participants p ON ct.target_participant_id = p.id
            JOIN rounds r ON p.round_id = r.id
            JOIN games g ON r.game_id = g.id
            WHERE ct.source_type = 'referral'
            AND ct.converted_at >= DATE_SUB(NOW(), INTERVAL ? DAY)
            GROUP BY g.id, g.name
            ORDER BY successful_conversions DESC
            LIMIT 10
        ";
        $analytics['top_games'] = Database::fetchAll($gameQuery, [$days]);

        return $analytics;
    }

    /**
     * Get fraud detection data
     *
     * @param int $days Number of days to analyze
     * @return array<string, mixed>
     */
    private function getFraudDetectionData(int $days): array
    {
        return FraudPrevention::getFraudStatistics($days);
    }

    /**
     * Get currently blocked IP addresses
     *
     * @return array<array<string, mixed>>
     */
    private function getBlockedIPs(): array
    {
        $query = "
            SELECT
                ip_address,
                MAX(blocked_until) as blocked_until,
                COUNT(*) as violation_count,
                GROUP_CONCAT(DISTINCT event_type) as event_types
            FROM security_log
            WHERE blocked_until > NOW()
            GROUP BY ip_address
            ORDER BY blocked_until DESC
        ";

        return Database::fetchAll($query);
    }

    /**
     * Get suspicious activity patterns
     *
     * @return array<array<string, mixed>>
     */
    private function getSuspiciousPatterns(): array
    {
        $query = "
            SELECT
                ip_address,
                COUNT(DISTINCT user_email) as unique_emails,
                COUNT(*) as total_participants,
                AVG(total_time_all_questions) as avg_completion_time
            FROM participants
            WHERE created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            AND payment_status = 'paid'
            GROUP BY ip_address
            HAVING unique_emails > 3 OR avg_completion_time < 30
            ORDER BY unique_emails DESC, avg_completion_time ASC
            LIMIT 20
        ";

        return Database::fetchAll($query);
    }

    /**
     * Export data to CSV
     *
     * @param array<array<string, mixed>> $data Data to export
     * @return void
     */
    private function exportToCsv(array $data): void
    {
        $filename = 'discounts_export_' . date('Y-m-d_H-i-s') . '.csv';

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, must-revalidate');

        $output = fopen('php://output', 'w');

        // CSV headers
        $headers = [
            'ID', 'Email', 'Type', 'Amount (%)', 'Code', 'Status',
            'Created', 'Used', 'Expires', 'Applied To', 'Game'
        ];
        fputcsv($output, $headers);

        // CSV data
        foreach ($data as $row) {
            $csvRow = [
                $row['id'],
                $row['email'],
                $row['action_type'],
                $row['discount_amount'],
                $row['discount_code'] ?? '',
                $this->getDiscountStatus($row),
                $row['created_at'],
                $row['used_at'] ?? '',
                $row['expires_at'] ?? '',
                $row['first_name'] . ' ' . $row['last_name'],
                $row['game_name'] ?? ''
            ];
            fputcsv($output, $csvRow);
        }

        fclose($output);
    }

    /**
     * Get export data based on filters
     *
     * @param array<string, mixed> $filters Filters
     * @return array<array<string, mixed>>
     */
    private function getExportData(array $filters): array
    {
        // Remove pagination for export
        $filters['page'] = 1;
        $filters['per_page'] = 10000; // Large number to get all records

        $result = $this->getFilteredDiscounts($filters);
        return $result['data'];
    }

    /**
     * Get discount status text
     *
     * @param array<string, mixed> $discount Discount record
     * @return string Status text
     */
    private function getDiscountStatus(array $discount): string
    {
        if ($discount['used_at']) {
            return 'Used';
        }
        if (!$discount['is_active']) {
            return 'Inactive';
        }
        if ($discount['expires_at'] && strtotime($discount['expires_at']) < time()) {
            return 'Expired';
        }
        return 'Active';
    }

    /**
     * Generate unique discount code
     *
     * @return string Discount code
     */
    private function generateDiscountCode(): string
    {
        do {
            $code = 'WIN' . strtoupper(bin2hex(random_bytes(4)));

            // Check if code already exists
            $existing = Database::fetchOne(
                "SELECT COUNT(*) as count FROM user_actions WHERE discount_code = ?",
                [$code]
            );
        } while ($existing['count'] > 0);

        return $code;
    }

    /**
     * Get available discount types
     *
     * @return array<string, string>
     */
    private function getDiscountTypes(): array
    {
        return [
            'manual' => 'Manual Discount',
            'bonus' => 'Bonus Discount',
            'referral' => 'Referral Credit',
            'replay' => 'Replay Discount'
        ];
    }

    /**
     * Calculate pagination data
     *
     * @param int $total Total records
     * @param int $perPage Records per page
     * @param int $currentPage Current page
     * @return array<string, mixed>
     */
    private function calculatePagination(int $total, int $perPage, int $currentPage): array
    {
        $totalPages = ceil($total / $perPage);

        return [
            'current_page' => $currentPage,
            'total_pages' => $totalPages,
            'per_page' => $perPage,
            'total_records' => $total,
            'has_previous' => $currentPage > 1,
            'has_next' => $currentPage < $totalPages,
            'previous_page' => max(1, $currentPage - 1),
            'next_page' => min($totalPages, $currentPage + 1)
        ];
    }

    /**
     * Log admin action for audit trail
     *
     * @param string $action Action performed
     * @param array<string, mixed> $details Action details
     * @return void
     */
    private function logAdminAction(string $action, array $details): void
    {
        $query = "
            INSERT INTO admin_audit_log
            (admin_id, action, details_json, ip_address, user_agent, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ";

        Database::execute($query, [
            $this->getCurrentAdminUser()['id'],
            $action,
            json_encode($details),
            $this->getClientIp(),
            $_SERVER['HTTP_USER_AGENT'] ?? ''
        ]);
    }
}
