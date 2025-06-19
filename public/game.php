<?php
declare(strict_types=1);

/**
 * File: public/game.php
 * Location: public/game.php
 *
 * WinABN Quiz Interface
 *
 * Handles the interactive quiz gameplay with real-time timing,
 * mobile-optimized interface, and secure answer submission.
 *
 * @package WinABN
 * @author WinABN Development Team
 * @version 1.0
 */

// Bootstrap the application
require_once __DIR__ . '/bootstrap.php';

use WinABN\Core\{Security, Database, View, Session};
use WinABN\Controllers\GameController;

// Initialize security headers
Security::setSecurityHeaders();

// Start secure session
session()->start();

// Validate session and game state
if (!session()->has('game_session_id') || !session()->has('participant_id')) {
    redirect(url('/'));
}

$gameController = new GameController();
$participantId = session()->get('participant_id');
$gameSessionId = session()->get('game_session_id');

try {
    // Get participant data
    $participant = Database::fetchOne("
        SELECT p.*, r.game_id, g.name as game_name, g.slug, g.question_timeout,
               g.total_questions, g.free_questions, r.status as round_status
        FROM participants p
        JOIN rounds r ON p.round_id = r.id
        JOIN games g ON r.game_id = g.id
        WHERE p.id = ? AND p.session_id = ?
    ", [$participantId, $gameSessionId]);

    if (!$participant) {
        throw new Exception('Invalid game session');
    }

    // Check if game is completed
    if ($participant['game_status'] === 'completed') {
        redirect(url('/game/complete'));
    }

    // Check if game failed
    if ($participant['game_status'] === 'failed') {
        redirect(url('/win-a-' . $participant['slug'] . '?failed=1'));
    }

    // Check payment status for questions 4-9
    $requiresPayment = $participant['current_question'] > $participant['free_questions'] &&
                      $participant['payment_status'] !== 'paid';

    if ($requiresPayment) {
        redirect(url('/pay.php'));
    }

    // Get current question
    $currentQuestion = $gameController->getCurrentQuestion($participantId);

    if (!$currentQuestion) {
        throw new Exception('No question available');
    }

    $view = new View();

    // Prepare view data
    $viewData = [
        'title' => 'Question ' . $participant['current_question'] . ' - ' . $participant['game_name'],
        'participant' => $participant,
        'question' => $currentQuestion,
        'current_question_number' => $participant['current_question'],
        'total_questions' => $participant['total_questions'],
        'free_questions' => $participant['free_questions'],
        'question_timeout' => $participant['question_timeout'],
        'game_session_id' => $gameSessionId,
        'is_free_question' => $participant['current_question'] <= $participant['free_questions'],
        'is_final_question' => $participant['current_question'] == $participant['total_questions'],
        'csrf_token' => csrf_token(),
        'progress_percentage' => round(($participant['current_question'] / $participant['total_questions']) * 100, 1)
    ];

    echo $view->render('game/quiz_interface', $viewData);

} catch (Exception $e) {
    app_log('error', 'Game interface error: ' . $e->getMessage(), [
        'participant_id' => $participantId ?? null,
        'session_id' => $gameSessionId ?? null,
        'error' => $e->getMessage()
    ]);

    if (is_debug()) {
        echo '<pre>' . $e . '</pre>';
    } else {
        redirect(url('/?error=game_error'));
    }
}
