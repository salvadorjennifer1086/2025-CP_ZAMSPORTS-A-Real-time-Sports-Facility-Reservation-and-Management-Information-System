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

// GET - Fetch ratings for a facility
if ($method === 'GET') {
	$facility_id = isset($_GET['facility_id']) ? (int)$_GET['facility_id'] : 0;
	
	if (!$facility_id) {
		echo json_encode(['success' => false, 'error' => 'Facility ID required']);
		exit;
	}
	
	// Get ratings with user info and replies
	$stmt = db()->prepare("
		SELECT 
			r.id,
			r.facility_id,
			r.user_id,
			r.reservation_id,
			r.rating,
			r.review_title,
			r.review_text,
			r.is_verified,
			r.is_anonymous,
			r.created_at,
			r.updated_at,
			u.full_name AS user_name,
			u.email AS user_email,
			COUNT(DISTINCT fr.id) AS reply_count
		FROM facility_ratings r
		LEFT JOIN users u ON u.id = r.user_id
		LEFT JOIN feedback_replies fr ON fr.rating_id = r.id
		WHERE r.facility_id = :facility_id
		GROUP BY r.id
		ORDER BY r.created_at DESC
	");
	
	$stmt->execute([':facility_id' => $facility_id]);
	$ratings = $stmt->fetchAll();
	
	// Get replies and vote counts for each rating
	foreach ($ratings as &$rating) {
		$rating_id = (int)$rating['id'];
		
		// Get vote counts for this rating
		$voteStmt = db()->prepare("
			SELECT 
				SUM(CASE WHEN vote_type = 'upvote' THEN 1 ELSE 0 END) AS upvotes,
				SUM(CASE WHEN vote_type = 'downvote' THEN 1 ELSE 0 END) AS downvotes
			FROM feedback_votes
			WHERE rating_id = :rating_id AND reply_id IS NULL
		");
		$voteStmt->execute([':rating_id' => $rating_id]);
		$voteCounts = $voteStmt->fetch();
		
		// Get user's vote for this rating
		$userVoteStmt = db()->prepare("
			SELECT vote_type 
			FROM feedback_votes 
			WHERE user_id = :user_id AND rating_id = :rating_id AND reply_id IS NULL
		");
		$userVoteStmt->execute([':user_id' => $user['id'], ':rating_id' => $rating_id]);
		$userVote = $userVoteStmt->fetch();
		
		$rating['upvotes'] = (int)$voteCounts['upvotes'];
		$rating['downvotes'] = (int)$voteCounts['downvotes'];
		$rating['user_vote'] = $userVote ? $userVote['vote_type'] : null;
		
		// Get replies for this rating
		$replyStmt = db()->prepare("
			SELECT 
				fr.id,
				fr.rating_id,
				fr.user_id,
				fr.reply_text,
				fr.is_facility_reply,
				fr.created_at,
				u.full_name AS user_name,
				u.email AS user_email,
				u.role AS user_role
			FROM feedback_replies fr
			LEFT JOIN users u ON u.id = fr.user_id
			WHERE fr.rating_id = :rating_id
			ORDER BY fr.created_at ASC
		");
		
		$replyStmt->execute([':rating_id' => $rating_id]);
		$replies = $replyStmt->fetchAll();
		
		// Get vote counts for each reply
		foreach ($replies as &$reply) {
			$reply_id = (int)$reply['id'];
			
			$replyVoteStmt = db()->prepare("
				SELECT 
					SUM(CASE WHEN vote_type = 'upvote' THEN 1 ELSE 0 END) AS upvotes,
					SUM(CASE WHEN vote_type = 'downvote' THEN 1 ELSE 0 END) AS downvotes
				FROM feedback_votes
				WHERE reply_id = :reply_id AND rating_id IS NULL
			");
			$replyVoteStmt->execute([':reply_id' => $reply_id]);
			$replyVoteCounts = $replyVoteStmt->fetch();
			
			$replyUserVoteStmt = db()->prepare("
				SELECT vote_type 
				FROM feedback_votes 
				WHERE user_id = :user_id AND reply_id = :reply_id AND rating_id IS NULL
			");
			$replyUserVoteStmt->execute([':user_id' => $user['id'], ':reply_id' => $reply_id]);
			$replyUserVote = $replyUserVoteStmt->fetch();
			
			$reply['upvotes'] = (int)$replyVoteCounts['upvotes'];
			$reply['downvotes'] = (int)$replyVoteCounts['downvotes'];
			$reply['user_vote'] = $replyUserVote ? $replyUserVote['vote_type'] : null;
		}
		
		$rating['replies'] = $replies;
	}
	
	// Calculate average rating
	$avgStmt = db()->prepare("
		SELECT 
			AVG(rating) AS average_rating,
			COUNT(*) AS total_ratings,
			SUM(CASE WHEN rating = 5 THEN 1 ELSE 0 END) AS rating_5,
			SUM(CASE WHEN rating = 4 THEN 1 ELSE 0 END) AS rating_4,
			SUM(CASE WHEN rating = 3 THEN 1 ELSE 0 END) AS rating_3,
			SUM(CASE WHEN rating = 2 THEN 1 ELSE 0 END) AS rating_2,
			SUM(CASE WHEN rating = 1 THEN 1 ELSE 0 END) AS rating_1
		FROM facility_ratings
		WHERE facility_id = :facility_id
	");
	
	$avgStmt->execute([':facility_id' => $facility_id]);
	$stats = $avgStmt->fetch();
	
	// Check if current user has already rated
	$userRatingStmt = db()->prepare("
		SELECT id, rating, review_title, review_text
		FROM facility_ratings
		WHERE facility_id = :facility_id AND user_id = :user_id
		LIMIT 1
	");
	
	$userRatingStmt->execute([
		':facility_id' => $facility_id,
		':user_id' => $user['id']
	]);
	$userRating = $userRatingStmt->fetch();
	
	echo json_encode([
		'success' => true,
		'ratings' => $ratings,
		'statistics' => [
			'average_rating' => round((float)$stats['average_rating'], 2),
			'total_ratings' => (int)$stats['total_ratings'],
			'rating_5' => (int)$stats['rating_5'],
			'rating_4' => (int)$stats['rating_4'],
			'rating_3' => (int)$stats['rating_3'],
			'rating_2' => (int)$stats['rating_2'],
			'rating_1' => (int)$stats['rating_1']
		],
		'user_rating' => $userRating
	]);
	exit;
}

// POST - Add a new rating
if ($method === 'POST') {
	$data = json_decode(file_get_contents('php://input'), true);
	
	$facility_id = isset($data['facility_id']) ? (int)$data['facility_id'] : 0;
	$rating = isset($data['rating']) ? (int)$data['rating'] : 0;
	$review_title = trim($data['review_title'] ?? '');
	$review_text = trim($data['review_text'] ?? '');
	$reservation_id = isset($data['reservation_id']) ? (int)$data['reservation_id'] : null;
	$is_anonymous = isset($data['is_anonymous']) ? (int)$data['is_anonymous'] : 0;
	
	if (!$facility_id || $rating < 1 || $rating > 5) {
		echo json_encode(['success' => false, 'error' => 'Invalid data']);
		exit;
	}
	
	// Check if facility exists
	$facilityStmt = db()->prepare("SELECT id FROM facilities WHERE id = :id AND is_active = 1");
	$facilityStmt->execute([':id' => $facility_id]);
	if (!$facilityStmt->fetch()) {
		echo json_encode(['success' => false, 'error' => 'Facility not found']);
		exit;
	}
	
	// Check if user already rated (update if exists)
	$existingStmt = db()->prepare("
		SELECT id FROM facility_ratings 
		WHERE facility_id = :facility_id AND user_id = :user_id
	");
	$existingStmt->execute([
		':facility_id' => $facility_id,
		':user_id' => $user['id']
	]);
	$existing = $existingStmt->fetch();
	
	if ($existing) {
		// Update existing rating
		$updateStmt = db()->prepare("
			UPDATE facility_ratings 
			SET rating = :rating,
				review_title = :review_title,
				review_text = :review_text,
				reservation_id = :reservation_id,
				is_anonymous = :is_anonymous,
				updated_at = NOW()
			WHERE id = :id
		");
		
		$updateStmt->execute([
			':id' => $existing['id'],
			':rating' => $rating,
			':review_title' => $review_title ?: null,
			':review_text' => $review_text ?: null,
			':reservation_id' => $reservation_id,
			':is_anonymous' => $is_anonymous
		]);
		
		$rating_id = $existing['id'];
	} else {
		// Insert new rating
		$insertStmt = db()->prepare("
			INSERT INTO facility_ratings 
			(facility_id, user_id, reservation_id, rating, review_title, review_text, is_anonymous)
			VALUES (:facility_id, :user_id, :reservation_id, :rating, :review_title, :review_text, :is_anonymous)
		");
		
		$insertStmt->execute([
			':facility_id' => $facility_id,
			':user_id' => $user['id'],
			':reservation_id' => $reservation_id,
			':rating' => $rating,
			':review_title' => $review_title ?: null,
			':review_text' => $review_text ?: null,
			':is_anonymous' => $is_anonymous
		]);
		
		$rating_id = db()->lastInsertId();
	}
	
	// Update facility average rating
	$avgStmt = db()->prepare("
		SELECT AVG(rating) AS avg_rating, COUNT(*) AS total
		FROM facility_ratings
		WHERE facility_id = :facility_id
	");
	$avgStmt->execute([':facility_id' => $facility_id]);
	$avgData = $avgStmt->fetch();
	
	$updateFacilityStmt = db()->prepare("
		UPDATE facilities 
		SET average_rating = :avg_rating, total_ratings = :total
		WHERE id = :facility_id
	");
	$updateFacilityStmt->execute([
		':facility_id' => $facility_id,
		':avg_rating' => round((float)$avgData['avg_rating'], 2),
		':total' => (int)$avgData['total']
	]);
	
	echo json_encode([
		'success' => true,
		'message' => $existing ? 'Rating updated successfully' : 'Rating added successfully',
		'rating_id' => $rating_id
	]);
	exit;
}

// DELETE - Delete a rating (only by owner or admin/staff)
if ($method === 'DELETE') {
	$rating_id = isset($_GET['rating_id']) ? (int)$_GET['rating_id'] : 0;
	
	if (!$rating_id) {
		echo json_encode(['success' => false, 'error' => 'Rating ID required']);
		exit;
	}
	
	// Check if rating exists and user has permission
	$checkStmt = db()->prepare("
		SELECT r.id, r.facility_id, r.user_id, f.id AS facility_exists
		FROM facility_ratings r
		LEFT JOIN facilities f ON f.id = r.facility_id
		WHERE r.id = :rating_id
	");
	$checkStmt->execute([':rating_id' => $rating_id]);
	$rating = $checkStmt->fetch();
	
	if (!$rating) {
		echo json_encode(['success' => false, 'error' => 'Rating not found']);
		exit;
	}
	
	// Check permission (owner or admin/staff)
	$isOwner = ((int)$rating['user_id'] === (int)$user['id']);
	$isAdminStaff = in_array($user['role'], ['admin', 'staff']);
	
	if (!$isOwner && !$isAdminStaff) {
		echo json_encode(['success' => false, 'error' => 'Permission denied']);
		exit;
	}
	
	// Delete rating (replies will be deleted via CASCADE)
	$deleteStmt = db()->prepare("DELETE FROM facility_ratings WHERE id = :rating_id");
	$deleteStmt->execute([':rating_id' => $rating_id]);
	
	// Update facility average rating
	$facility_id = (int)$rating['facility_id'];
	$avgStmt = db()->prepare("
		SELECT AVG(rating) AS avg_rating, COUNT(*) AS total
		FROM facility_ratings
		WHERE facility_id = :facility_id
	");
	$avgStmt->execute([':facility_id' => $facility_id]);
	$avgData = $avgStmt->fetch();
	
	$updateFacilityStmt = db()->prepare("
		UPDATE facilities 
		SET average_rating = :avg_rating, total_ratings = :total
		WHERE id = :facility_id
	");
	$updateFacilityStmt->execute([
		':facility_id' => $facility_id,
		':avg_rating' => $avgData['avg_rating'] ? round((float)$avgData['avg_rating'], 2) : null,
		':total' => (int)$avgData['total']
	]);
	
	echo json_encode(['success' => true, 'message' => 'Rating deleted successfully']);
	exit;
}

echo json_encode(['success' => false, 'error' => 'Invalid method']);

