<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/auth.php';

$method = $_SERVER['REQUEST_METHOD'];
$user = current_user();

if (!$user) {
	echo json_encode(['success' => false, 'error' => 'Not authenticated']);
	exit;
}

// POST - Add or update a vote
if ($method === 'POST') {
	$data = json_decode(file_get_contents('php://input'), true);
	
	$rating_id = isset($data['rating_id']) ? (int)$data['rating_id'] : null;
	$reply_id = isset($data['reply_id']) ? (int)$data['reply_id'] : null;
	$vote_type = isset($data['vote_type']) ? $data['vote_type'] : '';
	
	if ((!$rating_id && !$reply_id) || !in_array($vote_type, ['upvote', 'downvote'])) {
		echo json_encode(['success' => false, 'error' => 'Invalid data']);
		exit;
	}
	
	// Check if rating or reply exists
	if ($rating_id) {
		$checkStmt = db()->prepare("SELECT id FROM facility_ratings WHERE id = :rating_id");
		$checkStmt->execute([':rating_id' => $rating_id]);
		if (!$checkStmt->fetch()) {
			echo json_encode(['success' => false, 'error' => 'Rating not found']);
			exit;
		}
	}
	
	if ($reply_id) {
		$checkStmt = db()->prepare("SELECT id FROM feedback_replies WHERE id = :reply_id");
		$checkStmt->execute([':reply_id' => $reply_id]);
		if (!$checkStmt->fetch()) {
			echo json_encode(['success' => false, 'error' => 'Reply not found']);
			exit;
		}
	}
	
	// Check if user already voted
	$existingStmt = db()->prepare("
		SELECT id, vote_type 
		FROM feedback_votes 
		WHERE user_id = :user_id 
		AND " . ($rating_id ? "rating_id = :rating_id AND reply_id IS NULL" : "reply_id = :reply_id AND rating_id IS NULL") . "
	");
	
	if ($rating_id) {
		$existingStmt->execute([':user_id' => $user['id'], ':rating_id' => $rating_id]);
	} else {
		$existingStmt->execute([':user_id' => $user['id'], ':reply_id' => $reply_id]);
	}
	
	$existing = $existingStmt->fetch();
	
	if ($existing) {
		// If same vote type, remove vote (toggle off)
		if ($existing['vote_type'] === $vote_type) {
			$deleteStmt = db()->prepare("DELETE FROM feedback_votes WHERE id = :id");
			$deleteStmt->execute([':id' => $existing['id']]);
			
			$action = 'removed';
		} else {
			// Update to new vote type
			$updateStmt = db()->prepare("UPDATE feedback_votes SET vote_type = :vote_type WHERE id = :id");
			$updateStmt->execute([
				':vote_type' => $vote_type,
				':id' => $existing['id']
			]);
			
			$action = 'updated';
		}
	} else {
		// Insert new vote
		$insertStmt = db()->prepare("
			INSERT INTO feedback_votes 
			(rating_id, reply_id, user_id, vote_type)
			VALUES (:rating_id, :reply_id, :user_id, :vote_type)
		");
		
		$insertStmt->execute([
			':rating_id' => $rating_id,
			':reply_id' => $reply_id,
			':user_id' => $user['id'],
			':vote_type' => $vote_type
		]);
		
		$action = 'added';
	}
	
	// Get updated vote counts
	if ($rating_id) {
		$countStmt = db()->prepare("
			SELECT 
				SUM(CASE WHEN vote_type = 'upvote' THEN 1 ELSE 0 END) AS upvotes,
				SUM(CASE WHEN vote_type = 'downvote' THEN 1 ELSE 0 END) AS downvotes
			FROM feedback_votes
			WHERE rating_id = :rating_id AND reply_id IS NULL
		");
		$countStmt->execute([':rating_id' => $rating_id]);
	} else {
		$countStmt = db()->prepare("
			SELECT 
				SUM(CASE WHEN vote_type = 'upvote' THEN 1 ELSE 0 END) AS upvotes,
				SUM(CASE WHEN vote_type = 'downvote' THEN 1 ELSE 0 END) AS downvotes
			FROM feedback_votes
			WHERE reply_id = :reply_id AND rating_id IS NULL
		");
		$countStmt->execute([':reply_id' => $reply_id]);
	}
	
	$counts = $countStmt->fetch();
	
	// Get user's current vote
	$userVoteStmt = db()->prepare("
		SELECT vote_type 
		FROM feedback_votes 
		WHERE user_id = :user_id 
		AND " . ($rating_id ? "rating_id = :rating_id AND reply_id IS NULL" : "reply_id = :reply_id AND rating_id IS NULL") . "
	");
	
	if ($rating_id) {
		$userVoteStmt->execute([':user_id' => $user['id'], ':rating_id' => $rating_id]);
	} else {
		$userVoteStmt->execute([':user_id' => $user['id'], ':reply_id' => $reply_id]);
	}
	
	$userVote = $userVoteStmt->fetch();
	
	echo json_encode([
		'success' => true,
		'action' => $action,
		'upvotes' => (int)$counts['upvotes'],
		'downvotes' => (int)$counts['downvotes'],
		'user_vote' => $userVote ? $userVote['vote_type'] : null
	]);
	exit;
}

// GET - Get vote counts and user's vote
if ($method === 'GET') {
	$rating_id = isset($_GET['rating_id']) ? (int)$_GET['rating_id'] : null;
	$reply_id = isset($_GET['reply_id']) ? (int)$_GET['reply_id'] : null;
	
	if (!$rating_id && !$reply_id) {
		echo json_encode(['success' => false, 'error' => 'Rating ID or Reply ID required']);
		exit;
	}
	
	// Get vote counts
	if ($rating_id) {
		$countStmt = db()->prepare("
			SELECT 
				SUM(CASE WHEN vote_type = 'upvote' THEN 1 ELSE 0 END) AS upvotes,
				SUM(CASE WHEN vote_type = 'downvote' THEN 1 ELSE 0 END) AS downvotes
			FROM feedback_votes
			WHERE rating_id = :rating_id AND reply_id IS NULL
		");
		$countStmt->execute([':rating_id' => $rating_id]);
		
		$userVoteStmt = db()->prepare("
			SELECT vote_type 
			FROM feedback_votes 
			WHERE user_id = :user_id AND rating_id = :rating_id AND reply_id IS NULL
		");
		$userVoteStmt->execute([':user_id' => $user['id'], ':rating_id' => $rating_id]);
	} else {
		$countStmt = db()->prepare("
			SELECT 
				SUM(CASE WHEN vote_type = 'upvote' THEN 1 ELSE 0 END) AS upvotes,
				SUM(CASE WHEN vote_type = 'downvote' THEN 1 ELSE 0 END) AS downvotes
			FROM feedback_votes
			WHERE reply_id = :reply_id AND rating_id IS NULL
		");
		$countStmt->execute([':reply_id' => $reply_id]);
		
		$userVoteStmt = db()->prepare("
			SELECT vote_type 
			FROM feedback_votes 
			WHERE user_id = :user_id AND reply_id = :reply_id AND rating_id IS NULL
		");
		$userVoteStmt->execute([':user_id' => $user['id'], ':reply_id' => $reply_id]);
	}
	
	$counts = $countStmt->fetch();
	$userVote = $userVoteStmt->fetch();
	
	echo json_encode([
		'success' => true,
		'upvotes' => (int)$counts['upvotes'],
		'downvotes' => (int)$counts['downvotes'],
		'user_vote' => $userVote ? $userVote['vote_type'] : null
	]);
	exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid method']);

