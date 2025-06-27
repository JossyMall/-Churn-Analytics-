<?php
session_start();
if (!isset($_SESSION['user_id'])) {
    header('Location: auth/login.php');
    exit;
}

require_once 'includes/db.php';
require_once 'includes/header.php'; // Global header (likely contains <head> and initial <body> structure)

$user_id = $_SESSION['user_id'];



//$is_admin = $_SESSION['is_admin'] ?? false;

echo '<link rel="stylesheet" href="assets/css/helpdesk.css">';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_ticket'])) {
        try {
            $title = $_POST['title'];
            $description = $_POST['description'];
            $priority = $_POST['priority'];
            $stream_id = $_POST['stream_id'] ?? null;
            
            if (empty($stream_id)) {
                $stream_id = null;
            }
            
            $stmt = $pdo->prepare("INSERT INTO helpdesk_tickets 
                                  (user_id, title, description, priority, stream_id, status, created_at) 
                                  VALUES (?, ?, ?, ?, ?, 'open', NOW())");
            $stmt->execute([$user_id, $title, $description, $priority, $stream_id]);
            
            // Store success message in session
            $_SESSION['success'] = "Ticket created successfully!";
            
            // Redirect to prevent form resubmission
            header('Location: helpdesk.php');
            exit;
        } catch (PDOException $e) {
            $_SESSION['error'] = "Error creating ticket: " . $e->getMessage();
            header('Location: helpdesk.php');
            exit;
        }
    }
    elseif (isset($_POST['update_ticket'])) {
        try {
            $ticket_id = $_POST['ticket_id'];
            $response = $_POST['response'];
            $status = $_POST['status'];
            
            $stmt = $pdo->prepare("SELECT user_id FROM helpdesk_tickets WHERE id = ?");
            $stmt->execute([$ticket_id]);
            $ticket = $stmt->fetch();
            
            if ($ticket && ($is_admin || $ticket['user_id'] == $user_id)) {
                $stmt = $pdo->prepare("INSERT INTO helpdesk_responses 
                                      (ticket_id, user_id, response, created_at) 
                                      VALUES (?, ?, ?, NOW())");
                $stmt->execute([$ticket_id, $user_id, $response]);
                
                $stmt = $pdo->prepare("UPDATE helpdesk_tickets SET status = ? WHERE id = ?");
                $stmt->execute([$status, $ticket_id]);
                
                if ($is_admin) {
                    $notification_title = "Ticket Status Updated";
                    $notification_message = "Your ticket status has been updated to: " . ucfirst($status);
                    $related_url = "/io/helpdesk.php?ticket_id=" . $ticket_id;
                    
                    $stmt = $pdo->prepare("INSERT INTO notifications 
                                          (user_id, title, message, type, related_id, related_url) 
                                          VALUES (?, ?, ?, 'helpdesk', ?, ?)");
                    $stmt->execute([$ticket['user_id'], $notification_title, $notification_message, $ticket_id, $related_url]);
                }
                
                $_SESSION['success'] = "Ticket updated successfully!";
            } else {
                $_SESSION['error'] = "You don't have permission to update this ticket";
            }
            
            // Redirect back to the ticket page
            header('Location: helpdesk.php?ticket_id=' . $ticket_id);
            exit;
        } catch (PDOException $e) {
            $_SESSION['error'] = "Error updating ticket: " . $e->getMessage();
            header('Location: helpdesk.php?ticket_id=' . $_POST['ticket_id']);
            exit;
        }
    }
    elseif (isset($_POST['submit_review'])) {
        try {
            $ticket_id = $_POST['ticket_id'];
            $rating = $_POST['rating'];
            $review = $_POST['review'] ?? null;
            
            $stmt = $pdo->prepare("SELECT user_id FROM helpdesk_tickets WHERE id = ?");
            $stmt->execute([$ticket_id]);
            $ticket = $stmt->fetch();
            
            if ($ticket && $ticket['user_id'] == $user_id) {
                $stmt = $pdo->prepare("INSERT INTO ticket_reviews 
                                      (ticket_id, user_id, rating, review) 
                                      VALUES (?, ?, ?, ?)");
                $stmt->execute([$ticket_id, $user_id, $rating, $review]);
                
                $stmt = $pdo->prepare("UPDATE helpdesk_tickets SET reviewed = 1 WHERE id = ?");
                $stmt->execute([$ticket_id]);
                
                $_SESSION['success'] = "Thank you for your review!";
            } else {
                $_SESSION['error'] = "You can only review your own tickets";
            }
            
            // Redirect back to the ticket page
            header('Location: helpdesk.php?ticket_id=' . $ticket_id);
            exit;
        } catch (PDOException $e) {
            $_SESSION['error'] = "Error submitting review: " . $e->getMessage();
            header('Location: helpdesk.php?ticket_id=' . $_POST['ticket_id']);
            exit;
        }
    }
    elseif (isset($_POST['request_review'])) {
        try {
            $ticket_id = $_POST['ticket_id'];
            
            $stmt = $pdo->prepare("SELECT user_id, status FROM helpdesk_tickets WHERE id = ?");
            $stmt->execute([$ticket_id]);
            $ticket = $stmt->fetch();
            
            if ($is_admin && $ticket && in_array($ticket['status'], ['resolved', 'closed'])) {
                $stmt = $pdo->prepare("UPDATE helpdesk_tickets SET review_requested = 1 WHERE id = ?");
                $stmt->execute([$ticket_id]);
                
                $notification_title = "Please Rate Your Support Experience";
                $notification_message = "We'd appreciate your feedback on your recent support ticket";
                $related_url = "/io/helpdesk.php?ticket_id=" . $ticket_id;
                
                $stmt = $pdo->prepare("INSERT INTO notifications 
                                      (user_id, title, message, type, related_id, related_url) 
                                      VALUES (?, ?, ?, 'helpdesk', ?, ?)");
                $stmt->execute([$ticket['user_id'], $notification_title, $notification_message, $ticket_id, $related_url]);
                
                $_SESSION['success'] = "Review request sent to user";
            } else {
                $_SESSION['error'] = "You can only request reviews for resolved or closed tickets";
            }
            
            // Redirect back to the ticket page
            header('Location: helpdesk.php?ticket_id=' . $ticket_id);
            exit;
        } catch (PDOException $e) {
            $_SESSION['error'] = "Error requesting review: " . $e->getMessage();
            header('Location: helpdesk.php?ticket_id=' . $_POST['ticket_id']);
            exit;
        }
    }
}

// Get user's streams
try {
    $streams = [];
    $streams_query = $pdo->prepare("SELECT id, name FROM streams 
                              WHERE user_id = ? OR team_id IN (SELECT team_id FROM team_members WHERE user_id = ?)");
    $streams_query->execute([$user_id, $user_id]);
    $streams = $streams_query->fetchAll();
} catch (PDOException $e) {
    $_SESSION['error'] = "Error fetching streams: " . $e->getMessage();
}

// Get tickets
try {
    $tickets = [];
    if ($is_admin) {
        $tickets_query = $pdo->prepare("
            SELECT t.*, u.username, u.email, s.name as stream_name, p.company_name,
                   (SELECT COUNT(*) FROM ticket_reviews WHERE ticket_id = t.id) as has_review
            FROM helpdesk_tickets t
            LEFT JOIN users u ON t.user_id = u.id
            LEFT JOIN user_profiles p ON t.user_id = p.user_id
            LEFT JOIN streams s ON t.stream_id = s.id
            ORDER BY 
                CASE WHEN t.status = 'open' THEN 0 ELSE 1 END,
                CASE t.priority 
                    WHEN 'high' THEN 0 
                    WHEN 'medium' THEN 1 
                    WHEN 'low' THEN 2 
                    ELSE 3 
                END,
                t.created_at DESC
        ");
        $tickets_query->execute();
    } else {
        $tickets_query = $pdo->prepare("
            SELECT t.*, u.username, u.email, s.name as stream_name, p.company_name,
                   (SELECT COUNT(*) FROM ticket_reviews WHERE ticket_id = t.id) as has_review
            FROM helpdesk_tickets t
            LEFT JOIN users u ON t.user_id = u.id
            LEFT JOIN user_profiles p ON t.user_id = p.user_id
            LEFT JOIN streams s ON t.stream_id = s.id
            WHERE t.user_id = ?
            ORDER BY 
                CASE WHEN t.status = 'open' THEN 0 ELSE 1 END,
                CASE t.priority 
                    WHEN 'high' THEN 0 
                    WHEN 'medium' THEN 1 
                    WHEN 'low' THEN 2 
                    ELSE 3 
                END,
                t.created_at DESC
        ");
        $tickets_query->execute([$user_id]);
    }
    $tickets = $tickets_query->fetchAll();
} catch (PDOException $e) {
    $_SESSION['error'] = "Error fetching tickets: " . $e->getMessage();
}

// Get selected ticket
$responses = [];
$selected_ticket = null;
$ticket_review = null;
if (isset($_GET['ticket_id'])) {
    try {
        $ticket_id = intval($_GET['ticket_id']);
        
        $stmt = $pdo->prepare("
            SELECT t.*, u.username, u.email, p.company_name 
            FROM helpdesk_tickets t
            LEFT JOIN users u ON t.user_id = u.id
            LEFT JOIN user_profiles p ON t.user_id = p.user_id
            WHERE t.id = ? AND (t.user_id = ? OR ? = 1)
        ");
        
        $stmt->execute([$ticket_id, $user_id, $is_admin]);
        $selected_ticket = $stmt->fetch();
        
        if ($selected_ticket) {
            $stmt = $pdo->prepare("
                SELECT r.*, u.username, u.email, u.is_admin, p.company_name
                FROM helpdesk_responses r
                JOIN users u ON r.user_id = u.id
                LEFT JOIN user_profiles p ON r.user_id = p.user_id
                WHERE r.ticket_id = ?
                ORDER BY r.created_at ASC
            ");
            $stmt->execute([$ticket_id]);
            $responses = $stmt->fetchAll();
            
            $stmt = $pdo->prepare("SELECT * FROM ticket_reviews WHERE ticket_id = ?");
            $stmt->execute([$ticket_id]);
            $ticket_review = $stmt->fetch();
        } else {
            $_SESSION['error'] = "Access denied to ticket or ticket not found";
        }
    } catch (PDOException $e) {
        $_SESSION['error'] = "Error fetching ticket responses: " . $e->getMessage();
    }
}
?>

<div class="helpdesk-container">
    <h1>Helpdesk Support</h1>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['error']) ?></div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success"><?= htmlspecialchars($_SESSION['success']) ?></div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    
    <div class="helpdesk-layout">
        <div class="tickets-sidebar">
            <div class="tickets-header">
                <h2>Tickets</h2>
                <button id="new-ticket-btn" class="btn btn-primary">+ New Ticket</button>
            </div>
            
            <div class="tickets-list">
                <?php foreach ($tickets as $ticket): ?>
                    <a href="?ticket_id=<?= $ticket['id'] ?>" 
                       class="ticket-item <?= $selected_ticket && $selected_ticket['id'] == $ticket['id'] ? 'active' : '' ?>">
                        <div class="ticket-priority <?= $ticket['priority'] ?>">
                            <?= ucfirst($ticket['priority']) ?>
                        </div>
                        <div class="ticket-info">
                            <h3><?= htmlspecialchars($ticket['title']) ?></h3>
                            <div class="ticket-meta">
                                <span class="ticket-status <?= $ticket['status'] ?>">
                                    <?= ucfirst($ticket['status']) ?>
                                </span>
                                <span class="ticket-date">
                                    <?= date('M j, Y', strtotime($ticket['created_at'])) ?>
                                </span>
                                <?php if ($ticket['stream_name']): ?>
                                    <span class="ticket-stream">
                                        <?= htmlspecialchars($ticket['stream_name']) ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="ticket-detail">
            <?php if ($selected_ticket): ?>
                <div class="ticket-header">
                    <h2><?= htmlspecialchars($selected_ticket['title']) ?></h2>
                    <div class="ticket-status-badge <?= $selected_ticket['status'] ?>">
                        <?= ucfirst($selected_ticket['status']) ?>
                    </div>
                </div>
                
                <div class="ticket-meta">
                    <div class="meta-item">
                        <strong>Created by:</strong>
                        <?= htmlspecialchars($selected_ticket['email']) ?>
                        <?php if (!empty($selected_ticket['company_name'])): ?>
                            (<?= htmlspecialchars($selected_ticket['company_name']) ?>)
                        <?php endif; ?>
                        - <?= htmlspecialchars($selected_ticket['username']) ?>
                    </div>
                    <div class="meta-item">
                        <strong>Priority:</strong>
                        <span class="priority-badge <?= $selected_ticket['priority'] ?>">
                            <?= ucfirst($selected_ticket['priority']) ?>
                        </span>
                    </div>
                    <div class="meta-item">
                        <strong>Created:</strong>
                        <?= date('M j, Y H:i', strtotime($selected_ticket['created_at'])) ?>
                    </div>
                    <?php if ($selected_ticket['stream_name']): ?>
                        <div class="meta-item">
                            <strong>Related Stream:</strong>
                            <?= htmlspecialchars($selected_ticket['stream_name']) ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <div class="ticket-description">
                    <h3>Description</h3>
                    <p><?= nl2br(htmlspecialchars($selected_ticket['description'])) ?></p>
                </div>
                
                <?php if ($ticket_review): ?>
                    <div class="ticket-review">
                        <h3>Your Review</h3>
                        <div class="review-stars">
                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                <span class="review-star <?= $i <= $ticket_review['rating'] ? 'active' : '' ?>">★</span>
                            <?php endfor; ?>
                        </div>
                        <?php if ($ticket_review['review']): ?>
                            <div class="review-comment">
                                <p><?= nl2br(htmlspecialchars($ticket_review['review'])) ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php elseif (in_array($selected_ticket['status'], ['resolved', 'closed']) && $selected_ticket['user_id'] == $user_id && !$selected_ticket['reviewed']): ?>
                    <button class="btn btn-primary open-review-modal" data-ticket-id="<?= $selected_ticket['id'] ?>">
                        Rate Your Support Experience
                    </button>
                <?php endif; ?>
                
                <?php if ($is_admin && in_array($selected_ticket['status'], ['resolved', 'closed']) && !$selected_ticket['reviewed'] && !$selected_ticket['review_requested']): ?>
                    <form method="POST" class="request-review-form">
                        <input type="hidden" name="ticket_id" value="<?= $selected_ticket['id'] ?>">
                        <button type="submit" name="request_review" class="btn btn-secondary request-review-btn">
                            Request Review from User
                        </button>
                    </form>
                <?php endif; ?>
                
                <div class="ticket-responses">
                    <h3>Responses</h3>
                    
                    <?php if (empty($responses)): ?>
                        <p class="empty-responses">No responses yet</p>
                    <?php else: ?>
                        <?php foreach ($responses as $response): ?>
                            <div class="response <?= $response['is_admin'] ? 'admin-response' : 'user-response' ?>">
                                <div class="response-header">
                                    <strong>
                                        <?= htmlspecialchars($response['email']) ?>
                                        <?php if (!empty($response['company_name'])): ?>
                                            (<?= htmlspecialchars($response['company_name']) ?>)
                                        <?php endif; ?>
                                        - <?= htmlspecialchars($response['username']) ?>
                                    </strong>
                                    <span class="response-date">
                                        <?= date('M j, Y H:i', strtotime($response['created_at'])) ?>
                                    </span>
                                </div>
                                <div class="response-content">
                                    <?= nl2br(htmlspecialchars($response['response'])) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    
                    <?php if (!in_array($selected_ticket['status'], ['closed']) || $is_admin): ?>
                        <form method="POST" class="response-form">
                            <input type="hidden" name="ticket_id" value="<?= $selected_ticket['id'] ?>">
                            
                            <div class="form-group">
                                <label for="response">Your Response</label>
                                <textarea name="response" id="response" rows="4" required></textarea>
                            </div>
                            
                            <?php if ($is_admin): ?>
                                <div class="form-group">
                                    <label for="status">Update Status</label>
                                    <select name="status" id="status">
                                        <option value="open" <?= $selected_ticket['status'] == 'open' ? 'selected' : '' ?>>Open</option>
                                        <option value="pending" <?= $selected_ticket['status'] == 'pending' ? 'selected' : '' ?>>Pending</option>
                                        <option value="resolved" <?= $selected_ticket['status'] == 'resolved' ? 'selected' : '' ?>>Resolved</option>
                                        <option value="closed" <?= $selected_ticket['status'] == 'closed' ? 'selected' : '' ?>>Closed</option>
                                    </select>
                                </div>
                            <?php else: ?>
                                <input type="hidden" name="status" value="<?= $selected_ticket['status'] ?>">
                            <?php endif; ?>
                            
                            <button type="submit" name="update_ticket" class="btn btn-primary">
                                <?= $is_admin ? 'Update Ticket' : 'Add Response' ?>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="empty-ticket">
                    <div class="empty-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                            <polyline points="14 2 14 8 20 8"></polyline>
                            <line x1="16" y1="13" x2="8" y2="13"></line>
                            <line x1="16" y1="17" x2="8" y2="17"></line>
                            <polyline points="10 9 9 9 8 9"></polyline>
                        </svg>
                    </div>
                    <h3>No Ticket Selected</h3>
                    <p>Select a ticket from the sidebar or create a new one</p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- New Ticket Modal -->
<div class="modal" id="new-ticket-modal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Create New Ticket</h3>
            <button class="modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" id="ticket-form">
                <div class="form-group">
                    <label for="title">Title</label>
                    <input type="text" name="title" id="title" required>
                </div>
                
                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea name="description" id="description" rows="5" required></textarea>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="priority">Priority</label>
                        <select name="priority" id="priority" required>
                            <option value="high">High</option>
                            <option value="medium" selected>Medium</option>
                            <option value="low">Low</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="stream_id">Related Stream (Optional)</label>
                        <select name="stream_id" id="stream_id">
                            <option value="">-- None --</option>
                            <?php foreach ($streams as $stream): ?>
                                <option value="<?= $stream['id'] ?>"><?= htmlspecialchars($stream['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary modal-close">Cancel</button>
                    <button type="submit" name="create_ticket" class="btn btn-primary">Create Ticket</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Review Modal -->
<div class="modal" id="reviewModal">
    <div class="modal-content">
        <div class="modal-header">
            <h3>Rate Your Support Experience</h3>
            <button class="modal-close review-modal-close">&times;</button>
        </div>
        <div class="modal-body">
            <form method="POST" id="review-form">
                <input type="hidden" name="ticket_id" value="">
                
                <div class="form-group">
                    <label>Rating</label>
                    <div class="review-stars">
                        <span class="review-star" data-rating="1">★</span>
                        <span class="review-star" data-rating="2">★</span>
                        <span class="review-star" data-rating="3">★</span>
                        <span class="review-star" data-rating="4">★</span>
                        <span class="review-star" data-rating="5">★</span>
                    </div>
                    <input type="hidden" name="rating" value="0" required>
                </div>
                
                <div class="form-group">
                    <label for="review">Comments (Optional)</label>
                    <textarea name="review" id="review" rows="4"></textarea>
                </div>
                
                <div class="form-actions">
                    <button type="button" class="btn btn-secondary review-modal-close">Cancel</button>
                    <button type="submit" name="submit_review" class="btn btn-primary">Submit Review</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Modal handling
    const newTicketBtn = document.getElementById('new-ticket-btn');
    const newTicketModal = document.getElementById('new-ticket-modal');
    const reviewModal = document.getElementById('reviewModal');
    const modalCloseBtns = document.querySelectorAll('.modal-close');
    const reviewModalCloseBtns = document.querySelectorAll('.review-modal-close');
    
    // New Ticket Modal
    if (newTicketBtn && newTicketModal) {
        newTicketBtn.addEventListener('click', () => {
            newTicketModal.style.display = 'flex';
        });
    }
    
    // Review Modal
    document.querySelectorAll('.open-review-modal').forEach(btn => {
        btn.addEventListener('click', function() {
            const ticketId = this.getAttribute('data-ticket-id');
            document.querySelector('#review-form input[name="ticket_id"]').value = ticketId;
            reviewModal.style.display = 'flex';
        });
    });
    
    // Close modals
    modalCloseBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            this.closest('.modal').style.display = 'none';
        });
    });
    
    // Close review modal
    reviewModalCloseBtns.forEach(btn => {
        btn.addEventListener('click', function() {
            reviewModal.style.display = 'none';
        });
    });
    
    // Close when clicking outside
    document.querySelectorAll('.modal').forEach(modal => {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                this.style.display = 'none';
            }
        });
    });
    
    // Star rating functionality
    document.querySelectorAll('.review-star').forEach(star => {
        star.addEventListener('click', function() {
            const rating = parseInt(this.getAttribute('data-rating'));
            const starsContainer = this.parentElement;
            const hiddenInput = starsContainer.nextElementSibling;
            
            // Update visual stars
            starsContainer.querySelectorAll('.review-star').forEach((s, index) => {
                if (index < rating) {
                    s.classList.add('active');
                } else {
                    s.classList.remove('active');
                }
            });
            
            // Update hidden input
            hiddenInput.value = rating;
        });
    });
    
    // Auto-scroll responses
    const ticketContainer = document.querySelector('.ticket-responses');
    if (ticketContainer) {
        ticketContainer.scrollTop = ticketContainer.scrollHeight;
    }
});
</script>

<?php
require_once 'includes/footer.php';
?>