<?php
session_start();

require_once 'includes/db.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . BASE_URL . '/auth/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];

require_once 'team_functions.php';

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_team'])) {
        $name = trim($_POST['name']);
        $description = trim($_POST['description']);
        
        try {
            $pdo->beginTransaction();
            
            $stmt = $pdo->prepare("INSERT INTO teams (name, description, created_by) VALUES (?, ?, ?)");
            $stmt->execute([$name, $description, $user_id]);
            $team_id = $pdo->lastInsertId();
            
            $stmt = $pdo->prepare("INSERT INTO team_members (team_id, user_id, role, invited_by) 
                                 VALUES (?, ?, 'owner', ?)");
            $stmt->execute([$team_id, $user_id, $user_id]);
            
            $pdo->commit();
            $_SESSION['success'] = "Team created successfully!";
            header('Location: teams.php');
            exit;
        } catch (PDOException $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Error creating team: " . $e->getMessage();
            error_log("Error creating team: " . $e->getMessage());
        }
    }
    elseif (isset($_POST['delete_team'])) {
        $team_id = (int)$_POST['team_id'];
        
        $stmt = $pdo->prepare("SELECT id FROM teams WHERE id = ? AND created_by = ?");
        $stmt->execute([$team_id, $user_id]);
        
        if ($stmt->fetch()) {
            try {
                $pdo->beginTransaction();
                
                $tables_to_delete_from = ['team_members', 'team_invites', 'team_streams'];
                foreach ($tables_to_delete_from as $table) {
                    $stmt = $pdo->prepare("DELETE FROM $table WHERE team_id = ?");
                    $stmt->execute([$team_id]);
                }
                
                $stmt = $pdo->prepare("DELETE FROM teams WHERE id = ?");
                $stmt->execute([$team_id]);
                
                $pdo->commit();
                $_SESSION['success'] = "Team deleted successfully!";
                header('Location: teams.php');
                exit;
            } catch (PDOException $e) {
                $pdo->rollBack();
                $_SESSION['error'] = "Error deleting team: " . $e->getMessage();
                error_log("Error deleting team: " . $e->getMessage());
            }
        } else {
            $_SESSION['error'] = "Unauthorized: You do not own this team.";
        }
    }
    
    header('Location: teams.php');
    exit;
}

$teams = [];
try {
    $stmt = $pdo->prepare("
        SELECT 
            t.id, t.name, t.description, tm.role, t.created_by,
            COUNT(DISTINCT tmm.user_id) AS member_count,
            COUNT(DISTINCT ts.stream_id) AS stream_count
        FROM teams t
        JOIN team_members tm ON t.id = tm.team_id
        LEFT JOIN team_members tmm ON t.id = tmm.team_id
        LEFT JOIN team_streams ts ON t.id = ts.team_id
        WHERE tm.user_id = ?
        GROUP BY t.id, t.name, t.description, tm.role, t.created_by
        ORDER BY t.created_at DESC
    ");
    $stmt->execute([$user_id]);
    $teams = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching user teams: " . $e->getMessage());
    $_SESSION['error'] = "Error loading your teams: " . htmlspecialchars($e->getMessage());
}

if (!function_exists('generate_team_color')) {
    function generate_team_color($team_id) {
        $colors = ['#3ac3b8', '#4299e1', '#f6ad55', '#68d391', '#e53e3e', '#805ad5', '#ed8936'];
        return $colors[$team_id % count($colors)];
    }
}

require_once 'includes/header.php';
?>

<style>
.teams-container {
    background: #ffffff00;
    border-radius: 8px;
    padding: 20px;
}

.teams-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.new-team-btn {
    background: #3ac3b8;
    color: #000000;
    font-weight: 900;
    border: none;
    padding: 10px 20px;
    border-radius: 5px;
    cursor: pointer;
}
.new-team-btn:hover {
    background-color: #2da89e;
}

.alert {
    padding: 15px;
    margin-bottom: 20px;
    border-radius: 8px;
}

.alert.error {
    background: #f8e6e6;
    color: #dc3545;
}

.alert.success {
    background: #e6f7ee;
    color: #28a745;
}

.alert.info {
    background: #e0f2f7;
    color: #2b6cb0;
    border: 1px solid #b3e0ed;
}

.teams-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
}

.team-card {
    border: 1px solid var(--gray-200);
    border-radius: 8px;
    overflow: hidden;
    transition: transform 0.3s ease, box-shadow 0.3s ease;
    background-color: var(--white);
}

.team-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.team-card-header {
    padding: 15px;
    border-bottom: 1px solid var(--gray-200);
    display: flex;
    justify-content: space-between;
    align-items: center;
    background-color: var(--light-bg);
}

.team-card-header h3 {
    margin: 0;
    color: var(--dark);
}

.team-role {
    padding: 3px 8px;
    border-radius: 4px;
    font-size: 0.8rem;
    font-weight: bold;
}

.team-role.owner {
    background: var(--warning);
    color: var(--dark);
}

.team-role.editor {
    background: var(--info);
    color: white;
}

.team-role.viewer {
    background: var(--gray-500);
    color: white;
}

.team-card-body {
    padding: 15px;
}

.team-description {
    margin: 0 0 15px 0;
    color: var(--gray-700);
    line-height: 1.5;
}

.team-stats {
    display: flex;
    gap: 15px;
    margin-top: 15px;
}

.stat {
    flex: 1;
}

.stat-label {
    display: block;
    font-size: 0.8rem;
    color: var(--gray-600);
}

.stat-value {
    font-weight: 500;
    color: var(--dark);
}

.team-card-footer {
    display: flex;
    padding: 15px;
    border-top: 1px solid var(--gray-200);
    background: var(--secondary-bg);
    gap: 10px;
}

.action-btn {
    flex: 1;
    text-align: center;
    padding: 8px;
    background: var(--gray-200);
    color: var(--gray-700);
    border-radius: 4px;
    text-decoration: none;
    transition: background-color 0.2s ease, color 0.2s ease;
}

.action-btn:hover {
    background: var(--gray-300);
    color: var(--dark);
}

.delete-form {
    margin-left: 0;
}

.delete-btn {
    padding: 8px 15px;
    color: #ff0000;
    font-weight: 900;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    transition: background-color 0.2s ease;
}
.delete-btn:hover {
    background: var(--danger-dark);
}

.no-teams {
    text-align: center;
    padding: 40px 20px;
    background: var(--light-bg);
    border-radius: 8px;
    color: var(--gray-700);
}

.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    overflow: auto;
    align-items: center;
    justify-content: center;
}

.modal-content {
    background: white;
    margin: 10% auto;
    padding: 20px;
    border-radius: 8px;
    max-width: 600px;
    position: relative;
    box-shadow: 0 4px 10px rgba(0,0,0,0.2);
    transform: translate3d(0,0,0);
    max-height: 90vh;
    overflow-y: auto;
}
.modal-content h2 {
    cursor: grab;
    user-select: none;
    margin-bottom: 20px;
}

.close {
    position: absolute;
    right: 20px;
    top: 15px;
    font-size: 1.5rem;
    cursor: pointer;
    color: var(--gray-500);
    transition: color 0.2s ease;
}
.close:hover {
    color: var(--dark);
}

.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 500;
    color: var(--gray-700);
}

.form-group input[type="text"],
.form-group textarea {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid var(--gray-300);
    border-radius: 4px;
    font-size: 1rem;
    color: var(--dark);
}
.form-group input:focus, .form-group textarea:focus {
    outline: none;
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(58, 195, 184, 0.1);
}

.submit-btn {
    width: 100%;
    padding: 10px;
    background: #2da89e;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    margin-top: 10px;
    transition: background 0.2s ease;
}
.submit-btn:hover {
    background: var(--primary-dark);
}

.editor-toolbar {
    display: flex;
    gap: 5px;
    margin-bottom: 5px;
}

.editor-btn {
    padding: 5px 10px;
    background: var(--gray-200);
    border: 1px solid var(--gray-300);
    border-radius: 3px;
    cursor: pointer;
    transition: background 0.2s ease;
}
.editor-btn:hover {
    background: var(--gray-300);
}

.editor-container {
    position: relative;
}

#teamDescription {
    width: 100%;
    min-height: 100px;
    resize: vertical;
    border: 1px solid var(--gray-300);
    border-radius: 4px;
    padding: 8px 12px;
    font-family: inherit;
}

.editor-preview {
    display: none;
    padding: 8px 12px;
    border: 1px solid var(--gray-300);
    border-radius: 4px;
    min-height: 100px;
    background: var(--white);
    margin-top: 10px;
}
</style>

<div class="teams-container">
    <div class="teams-header">
        <h1>Your Teams</h1>
        <button class="new-team-btn" id="newTeamBtn">+ New Team</button>
    </div>
    
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert error"><?= $_SESSION['error'] ?></div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert success"><?= $_SESSION['success'] ?></div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['info'])): ?>
        <div class="alert info"><?= $_SESSION['info'] ?></div>
        <?php unset($_SESSION['info']); ?>
    <?php endif; ?>
    
    <?php if (count($teams)): ?>
        <div class="teams-grid">
            <?php foreach ($teams as $team): ?>
                <div class="team-card" style="border-left: 4px solid <?= generate_team_color($team['id']) ?>">
                    <div class="team-card-header">
                        <h3><?= htmlspecialchars($team['name']) ?></h3>
                        <span class="team-role <?= $team['role'] ?>"><?= ucfirst($team['role']) ?></span>
                    </div>
                    
                    <div class="team-card-body">
                        <div class="team-description"><?= nl2br(htmlspecialchars($team['description'] ?: 'No description')) ?></div>
                        
                        <div class="team-stats">
                            <div class="stat">
                                <span class="stat-label">Members</span>
                                <span class="stat-value"><?= $team['member_count'] ?? 0 ?></span>
                            </div>
                            <div class="stat">
                                <span class="stat-label">Streams</span>
                                <span class="stat-value"><?= $team['stream_count'] ?? 0 ?></span>
                            </div>
                        </div>
                    </div>
                    
                    <div class="team-card-footer">
                        <a href="team.php?id=<?= $team['id'] ?>" class="action-btn">View Team</a>
                        <?php if ($team['role'] === 'owner'): ?>
                            <form method="POST" class="delete-form">
                                <input type="hidden" name="team_id" value="<?= $team['id'] ?>">
                                <button type="submit" name="delete_team" class="delete-btn" onclick="return confirm('Are you sure you want to delete this team and all its members and shared streams? This cannot be undone!');">Delete</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="no-teams">
            <p>👋 You don't have any teams yet. Create your first team to collaborate with others.</p>
        </div>
    <?php endif; ?>
</div>

<div class="modal" id="newTeamModal">
    <div class="modal-content">
        <span class="close">&times;</span>
        <h2>Create New Team</h2>
        
        <form method="POST">
            <div class="form-group">
                <label>Team Name</label>
                <input type="text" name="name" required>
            </div>
            
            <div class="form-group">
                <label>Description</label>
                <div class="editor-toolbar">
                    <button type="button" class="editor-btn" data-command="bold" title="Bold"><b>B</b></button>
                    <button type="button" class="editor-btn" data-command="italic" title="Italic"><i>I</i></button>
                    <button type="button" class="editor-btn" data-command="underline" title="Underline"><u>U</u></button>
                    <button type="button" class="editor-btn" data-command="insertUnorderedList" title="Bullet List">• List</button>
                    <button type="button" class="editor-btn" data-command="insertLink" title="Insert Link">🔗</button>
                </div>
                <div class="editor-container">
                    <textarea id="teamDescription" name="description" rows="5"></textarea>
                    <div id="descriptionPreview" class="editor-preview"></div>
                </div>
            </div>
            
            <button type="submit" name="create_team" class="submit-btn">Create Team</button>
        </form>
    </div>
</div>

<script>
    const modal = document.getElementById('newTeamModal');
    const newTeamBtn = document.getElementById('newTeamBtn');
    const closeBtn = modal.querySelector('.close');
    
    newTeamBtn.addEventListener('click', () => {
        modal.style.display = 'block';
        const modalContent = modal.querySelector('.modal-content');
        modalContent.style.transform = "translate3d(0px, 0px, 0)";
        modalContent.scrollTop = 0;
    });
    
    closeBtn.addEventListener('click', () => {
        modal.style.display = 'none';
    });
    
    window.addEventListener('click', (e) => {
        if (e.target === modal) {
            modal.style.display = 'none';
        }
    });
    
    document.addEventListener('DOMContentLoaded', function() {
        const descriptionField = document.getElementById('teamDescription');
        const previewArea = document.getElementById('descriptionPreview');
        const editorButtons = document.querySelectorAll('.editor-btn');
        
        editorButtons.forEach(button => {
            button.addEventListener('click', function(event) {
                event.preventDefault();
                const command = this.dataset.command;
                
                const start = descriptionField.selectionStart;
                const end = descriptionField.selectionEnd;
                const selectedText = descriptionField.value.substring(start, end);
                let newText = '';
                let cursorOffset = 0;

                switch (command) {
                    case 'bold':
                        newText = `**${selectedText}**`;
                        cursorOffset = 2;
                        break;
                    case 'italic':
                        newText = `*${selectedText}*`;
                        cursorOffset = 1;
                        break;
                    case 'underline':
                        newText = `_${selectedText}_`;
                        cursorOffset = 1;
                        break;
                    case 'insertUnorderedList':
                        if (selectedText.length === 0) {
                            newText = `* `;
                            cursorOffset = 2;
                        } else {
                            const lines = selectedText.split('\n');
                            newText = lines.map(line => `* ${line}`).join('\n');
                            cursorOffset = 2;
                        }
                        break;
                    case 'insertLink':
                        const url = prompt('Enter the URL:');
                        if (url) {
                            const linkText = prompt('Enter the link text (optional):', selectedText || url);
                            if (linkText !== null) {
                                newText = `[${linkText}](${url})`;
                                cursorOffset = linkText.length + 1;
                            } else {
                                return;
                            }
                        } else {
                            return;
                        }
                        break;
                    default:
                        return;
                }

                const originalValue = descriptionField.value;
                descriptionField.value = originalValue.substring(0, start) + newText + originalValue.substring(end);

                if (selectedText.length === 0) {
                    descriptionField.selectionStart = descriptionField.selectionEnd = start + cursorOffset;
                } else {
                    descriptionField.selectionStart = start;
                    descriptionField.selectionEnd = start + newText.length;
                }
                
                descriptionField.focus();
            });
        });
    });

    const newTeamModalContent = document.getElementById('newTeamModal').querySelector('.modal-content');
    if (newTeamModalContent) {
        let xOffset = 0;
        let yOffset = 0;
        let isDragging = false;
        let initialX, initialY;

        const handle = newTeamModalContent.querySelector('h2');

        if (handle) {
            handle.addEventListener('mousedown', dragStart);
        }

        function dragStart(e) {
            initialX = e.clientX - xOffset;
            initialY = e.clientY - yOffset;
            if (e.button === 0) {
                isDragging = true;
                handle.style.cursor = 'grabbing';
                document.addEventListener('mousemove', drag);
                document.addEventListener('mouseup', dragEnd);
            }
        }

        function drag(e) {
            if (isDragging) {
                e.preventDefault();
                xOffset = e.clientX - initialX;
                yOffset = e.clientY - initialY;
                setTranslate(xOffset, yOffset, newTeamModalContent);
            }
        }

        function dragEnd() {
            isDragging = false;
            handle.style.cursor = 'grab';
            document.removeEventListener('mousemove', drag);
            document.removeEventListener('mouseup', dragEnd);
        }

        function setTranslate(xPos, yPos, el) {
            el.style.transform = `translate3d(${xPos}px, ${yPos}px, 0)`;
        }
    }
</script>

<?php
require_once 'includes/footer.php';
?>