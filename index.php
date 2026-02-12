<?php
// ============================================
// SINGLE FILE COMPLETE CHAT APPLICATION
// ============================================

session_start();

// ========== DATABASE SETUP ==========
$db_file = 'chat_app.db';

try {
    $pdo = new PDO("sqlite:$db_file");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Create tables
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            email TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            full_name TEXT,
            bio TEXT,
            profile_pic TEXT DEFAULT 'default.jpg',
            status TEXT DEFAULT 'offline',
            last_seen DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );
        
        CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            sender_id INTEGER NOT NULL,
            receiver_id INTEGER NOT NULL,
            message TEXT,
            file_path TEXT,
            is_read INTEGER DEFAULT 0,
            delivered INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
        );
        
        CREATE TABLE IF NOT EXISTS calls (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            caller_id INTEGER NOT NULL,
            receiver_id INTEGER NOT NULL,
            call_type TEXT,
            status TEXT DEFAULT 'initiated',
            start_time DATETIME DEFAULT CURRENT_TIMESTAMP,
            end_time DATETIME,
            FOREIGN KEY (caller_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
        );
        
        CREATE TABLE IF NOT EXISTS notifications (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL,
            type TEXT NOT NULL,
            content TEXT NOT NULL,
            is_read INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );
    ");
    
    // Create uploads directory
    if (!file_exists('uploads')) {
        mkdir('uploads', 0777, true);
    }
    
    // Create default profile picture if not exists
    if (!file_exists('uploads/default.jpg')) {
        copy('https://via.placeholder.com/150', 'uploads/default.jpg');
    }
    
} catch(PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// ========== HELPER FUNCTIONS ==========
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function getUserById($id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function getUserStatus($id) {
    global $pdo;
    $stmt = $pdo->prepare("SELECT status, last_seen FROM users WHERE id = ?");
    $stmt->execute([$id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user['status'] == 'online') {
        return 'online';
    } else {
        $last_seen = strtotime($user['last_seen']);
        $diff = time() - $last_seen;
        
        if ($diff < 60) return 'Just now';
        if ($diff < 3600) return floor($diff / 60) . ' minutes ago';
        if ($diff < 86400) return floor($diff / 3600) . ' hours ago';
        return floor($diff / 86400) . ' days ago';
    }
}

// ========== ROUTING ==========
$action = $_GET['action'] ?? 'home';

// API Endpoints
if ($action == 'api_login') {
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            
            $update = $pdo->prepare("UPDATE users SET status = 'online', last_seen = CURRENT_TIMESTAMP WHERE id = ?");
            $update->execute([$user['id']]);
            
            echo json_encode(['success' => true, 'user' => $user]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Invalid credentials']);
        }
    }
    exit;
}

if ($action == 'api_register') {
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] == 'POST') {
        $username = $_POST['username'] ?? '';
        $email = $_POST['email'] ?? '';
        $password = password_hash($_POST['password'] ?? '', PASSWORD_DEFAULT);
        $full_name = $_POST['full_name'] ?? '';
        
        try {
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password, full_name) VALUES (?, ?, ?, ?)");
            $stmt->execute([$username, $email, $password, $full_name]);
            echo json_encode(['success' => true]);
        } catch(PDOException $e) {
            echo json_encode(['success' => false, 'error' => 'Username or email already exists']);
        }
    }
    exit;
}

if ($action == 'api_logout') {
    if (isLoggedIn()) {
        $stmt = $pdo->prepare("UPDATE users SET status = 'offline', last_seen = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$_SESSION['user_id']]);
        session_destroy();
    }
    echo json_encode(['success' => true]);
    exit;
}

if ($action == 'api_get_users') {
    header('Content-Type: application/json');
    if (!isLoggedIn()) {
        echo json_encode(['error' => 'Not logged in']);
        exit;
    }
    
    $current_user = $_SESSION['user_id'];
    
    $stmt = $pdo->prepare("
        SELECT u.*, 
            (SELECT COUNT(*) FROM messages 
             WHERE sender_id = u.id AND receiver_id = ? AND is_read = 0) as unread_count,
            (SELECT message FROM messages 
             WHERE (sender_id = u.id AND receiver_id = ?) 
                OR (sender_id = ? AND receiver_id = u.id) 
             ORDER BY created_at DESC LIMIT 1) as last_message
        FROM users u 
        WHERE u.id != ? 
        ORDER BY u.status DESC, u.last_seen DESC
    ");
    $stmt->execute([$current_user, $current_user, $current_user, $current_user]);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($users as &$user) {
        $user['status_text'] = getUserStatus($user['id']);
    }
    
    echo json_encode($users);
    exit;
}

if ($action == 'api_get_messages') {
    header('Content-Type: application/json');
    if (!isLoggedIn() || !isset($_GET['user_id'])) {
        echo json_encode(['error' => 'Invalid request']);
        exit;
    }
    
    $current_user = $_SESSION['user_id'];
    $other_user = $_GET['user_id'];
    
    $stmt = $pdo->prepare("
        SELECT m.*, u.username, u.full_name, u.profile_pic 
        FROM messages m 
        JOIN users u ON m.sender_id = u.id 
        WHERE (m.sender_id = ? AND m.receiver_id = ?) 
           OR (m.sender_id = ? AND m.receiver_id = ?) 
        ORDER BY m.created_at ASC
    ");
    $stmt->execute([$current_user, $other_user, $other_user, $current_user]);
    $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($messages);
    exit;
}

if ($action == 'api_send_message') {
    header('Content-Type: application/json');
    if (!isLoggedIn() || $_SERVER['REQUEST_METHOD'] != 'POST') {
        echo json_encode(['error' => 'Invalid request']);
        exit;
    }
    
    $sender_id = $_SESSION['user_id'];
    $receiver_id = $_POST['receiver_id'] ?? 0;
    $message = $_POST['message'] ?? '';
    
    $stmt = $pdo->prepare("INSERT INTO messages (sender_id, receiver_id, message, delivered) VALUES (?, ?, ?, 0)");
    $stmt->execute([$sender_id, $receiver_id, $message]);
    
    // Add notification
    $sender = getUserById($sender_id);
    $notif = $pdo->prepare("INSERT INTO notifications (user_id, type, content) VALUES (?, 'message', ?)");
    $notif->execute([$receiver_id, "New message from " . $sender['username']]);
    
    echo json_encode(['success' => true, 'message_id' => $pdo->lastInsertId()]);
    exit;
}

if ($action == 'api_mark_read') {
    if (!isLoggedIn() || !isset($_POST['sender_id'])) {
        exit;
    }
    
    $current_user = $_SESSION['user_id'];
    $sender_id = $_POST['sender_id'];
    
    $stmt = $pdo->prepare("UPDATE messages SET is_read = 1 WHERE sender_id = ? AND receiver_id = ? AND is_read = 0");
    $stmt->execute([$sender_id, $current_user]);
    exit;
}

if ($action == 'api_update_profile') {
    header('Content-Type: application/json');
    if (!isLoggedIn() || $_SERVER['REQUEST_METHOD'] != 'POST') {
        echo json_encode(['error' => 'Invalid request']);
        exit;
    }
    
    $user_id = $_SESSION['user_id'];
    $full_name = $_POST['full_name'] ?? '';
    $bio = $_POST['bio'] ?? '';
    $email = $_POST['email'] ?? '';
    
    // Handle profile picture upload
    $profile_pic = null;
    if (isset($_FILES['profile_pic']) && $_FILES['profile_pic']['error'] == 0) {
        $allowed = ['jpg', 'jpeg', 'png', 'gif'];
        $filename = $_FILES['profile_pic']['name'];
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed) && $_FILES['profile_pic']['size'] <= 5242880) {
            $new_filename = uniqid() . '.' . $ext;
            if (move_uploaded_file($_FILES['profile_pic']['tmp_name'], 'uploads/' . $new_filename)) {
                $profile_pic = $new_filename;
            }
        }
    }
    
    if ($profile_pic) {
        $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, bio = ?, profile_pic = ? WHERE id = ?");
        $stmt->execute([$full_name, $email, $bio, $profile_pic, $user_id]);
    } else {
        $stmt = $pdo->prepare("UPDATE users SET full_name = ?, email = ?, bio = ? WHERE id = ?");
        $stmt->execute([$full_name, $email, $bio, $user_id]);
    }
    
    echo json_encode(['success' => true]);
    exit;
}

if ($action == 'api_initiate_call') {
    header('Content-Type: application/json');
    if (!isLoggedIn()) {
        echo json_encode(['error' => 'Not logged in']);
        exit;
    }
    
    $caller_id = $_SESSION['user_id'];
    $receiver_id = $_POST['receiver_id'] ?? 0;
    $call_type = $_POST['call_type'] ?? 'video';
    
    $stmt = $pdo->prepare("INSERT INTO calls (caller_id, receiver_id, call_type, status) VALUES (?, ?, ?, 'initiated')");
    $stmt->execute([$caller_id, $receiver_id, $call_type]);
    
    $caller = getUserById($caller_id);
    $notif = $pdo->prepare("INSERT INTO notifications (user_id, type, content) VALUES (?, 'call', ?)");
    $notif->execute([$receiver_id, "Incoming " . $call_type . " call from " . $caller['username']]);
    
    echo json_encode(['success' => true, 'call_id' => $pdo->lastInsertId()]);
    exit;
}

if ($action == 'api_end_call') {
    if (!isLoggedIn()) {
        exit;
    }
    
    $current_user = $_SESSION['user_id'];
    $receiver_id = $_POST['receiver_id'] ?? 0;
    
    $stmt = $pdo->prepare("UPDATE calls SET status = 'ended', end_time = CURRENT_TIMESTAMP 
                          WHERE ((caller_id = ? AND receiver_id = ?) OR (caller_id = ? AND receiver_id = ?)) 
                          AND status = 'ongoing'");
    $stmt->execute([$current_user, $receiver_id, $receiver_id, $current_user]);
    exit;
}

// ========== MAIN APPLICATION PAGE ==========
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chat Application</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .app-container {
            max-width: 1400px;
            margin: 20px auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            height: calc(100vh - 40px);
            overflow: hidden;
        }

        /* Auth Styles */
        .auth-container {
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .auth-card {
            background: white;
            border-radius: 20px;
            padding: 40px;
            width: 100%;
            max-width: 450px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: slideIn 0.5s ease;
        }

        @keyframes slideIn {
            from {
                transform: translateY(-50px);
                opacity: 0;
            }
            to {
                transform: translateY(0);
                opacity: 1;
            }
        }

        .auth-header {
            text-align: center;
            margin-bottom: 30px;
        }

        .auth-header h2 {
            color: #667eea;
            font-weight: 600;
        }

        .auth-header i {
            font-size: 50px;
            color: #667eea;
            margin-bottom: 10px;
        }

        /* Dashboard Styles */
        .dashboard {
            display: none;
            height: 100%;
        }

        .chat-container {
            display: flex;
            height: 100%;
        }

        .sidebar {
            width: 30%;
            background: #f8f9fa;
            border-right: 1px solid #dee2e6;
            display: flex;
            flex-direction: column;
        }

        .user-profile {
            padding: 20px;
            border-bottom: 1px solid #dee2e6;
            background: white;
        }

        .user-info {
            display: flex;
            align-items: center;
        }

        .profile-pic {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #667eea;
        }

        .user-details {
            margin-left: 15px;
            flex: 1;
        }

        .user-details h6 {
            margin: 0;
            font-weight: 600;
        }

        .user-details small {
            color: #6c757d;
        }

        .action-buttons {
            display: flex;
            gap: 10px;
        }

        .action-btn {
            background: none;
            border: none;
            color: #667eea;
            font-size: 1.2rem;
            cursor: pointer;
            padding: 5px;
            transition: all 0.3s;
        }

        .action-btn:hover {
            transform: scale(1.1);
        }

        .users-list {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
        }

        .user-item {
            display: flex;
            align-items: center;
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 10px;
            background: white;
            cursor: pointer;
            transition: all 0.3s;
            border: 1px solid transparent;
        }

        .user-item:hover {
            background: #e3f2fd;
            border-color: #667eea;
            transform: translateX(5px);
        }

        .user-item.active {
            background: #e3f2fd;
            border-color: #667eea;
        }

        .online-status {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            display: inline-block;
            margin-right: 5px;
        }

        .status-online {
            background: #28a745;
            box-shadow: 0 0 10px #28a745;
        }

        .status-offline {
            background: #dc3545;
        }

        .unread-badge {
            background: #ffc107;
            color: #000;
            padding: 3px 8px;
            border-radius: 15px;
            font-size: 0.7rem;
            margin-left: 10px;
        }

        /* Chat Area Styles */
        .chat-area {
            width: 70%;
            display: flex;
            flex-direction: column;
            background: white;
        }

        .chat-header {
            padding: 20px;
            border-bottom: 1px solid #dee2e6;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: white;
        }

        .call-buttons {
            display: flex;
            gap: 10px;
        }

        .call-btn {
            padding: 8px 15px;
            border: none;
            border-radius: 20px;
            color: white;
            cursor: pointer;
            transition: all 0.3s;
        }

        .call-btn:hover {
            transform: scale(1.05);
        }

        .audio-call {
            background: #17a2b8;
        }

        .video-call {
            background: #28a745;
        }

        .messages-container {
            flex: 1;
            overflow-y: auto;
            padding: 20px;
            background: #f8f9fa;
        }

        .message {
            display: flex;
            margin-bottom: 20px;
            animation: fadeIn 0.3s ease;
        }

        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .message.sent {
            justify-content: flex-end;
        }

        .message.received {
            justify-content: flex-start;
        }

        .message-content {
            max-width: 70%;
            padding: 12px 18px;
            border-radius: 20px;
            position: relative;
            word-wrap: break-word;
        }

        .message.received .message-content {
            background: white;
            border: 1px solid #dee2e6;
            border-bottom-left-radius: 5px;
        }

        .message.sent .message-content {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-bottom-right-radius: 5px;
        }

        .message-time {
            font-size: 0.7rem;
            margin-top: 5px;
            opacity: 0.7;
        }

        .message.sent .message-time {
            text-align: right;
        }

        .message-input-area {
            padding: 20px;
            border-top: 1px solid #dee2e6;
            background: white;
        }

        .message-form {
            display: flex;
            gap: 10px;
        }

        .message-input {
            flex: 1;
            padding: 12px;
            border: 1px solid #dee2e6;
            border-radius: 25px;
            outline: none;
            transition: all 0.3s;
        }

        .message-input:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }

        .send-btn {
            width: 45px;
            height: 45px;
            border-radius: 50%;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            cursor: pointer;
            transition: all 0.3s;
        }

        .send-btn:hover {
            transform: scale(1.1);
        }

        /* Modal Styles */
        .modal-content {
            border-radius: 15px;
            border: none;
        }

        .modal-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px 15px 0 0;
        }

        .modal-header .btn-close {
            filter: brightness(0) invert(1);
        }

        .video-container {
            display: flex;
            gap: 20px;
            margin-top: 20px;
        }

        .video-box {
            flex: 1;
            background: #000;
            border-radius: 10px;
            overflow: hidden;
            position: relative;
        }

        .video-box video {
            width: 100%;
            height: auto;
            display: block;
        }

        .video-label {
            position: absolute;
            bottom: 10px;
            left: 10px;
            color: white;
            background: rgba(0,0,0,0.5);
            padding: 5px 10px;
            border-radius: 5px;
        }

        .profile-edit-form {
            padding: 20px;
        }

        .current-profile-pic {
            width: 150px;
            height: 150px;
            border-radius: 50%;
            object-fit: cover;
            margin-bottom: 20px;
            border: 3px solid #667eea;
        }

        .loading {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 2px solid #f3f3f3;
            border-top: 2px solid #667eea;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .toast-notification {
            position: fixed;
            top: 20px;
            right: 20px;
            background: white;
            border-radius: 10px;
            padding: 15px 20px;
            box-shadow: 0 5px 20px rgba(0,0,0,0.2);
            display: flex;
            align-items: center;
            gap: 10px;
            z-index: 9999;
            animation: slideInRight 0.3s ease;
        }

        @keyframes slideInRight {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }

        .toast-success { border-left: 4px solid #28a745; }
        .toast-error { border-left: 4px solid #dc3545; }
        .toast-info { border-left: 4px solid #17a2b8; }

        @media (max-width: 768px) {
            .app-container {
                margin: 0;
                height: 100vh;
                border-radius: 0;
            }
            
            .sidebar {
                width: 100%;
                display: none;
            }
            
            .sidebar.active {
                display: flex;
            }
            
            .chat-area {
                width: 100%;
                display: none;
            }
            
            .chat-area.active {
                display: flex;
            }
            
            .back-btn {
                display: block !important;
            }
        }

        .back-btn {
            display: none;
            background: none;
            border: none;
            color: #667eea;
            font-size: 1.2rem;
            margin-right: 15px;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <!-- Auth Section -->
    <div id="authSection" class="auth-container">
        <div class="auth-card">
            <div class="auth-header">
                <i class="fas fa-comments"></i>
                <h2>Welcome to ChatApp</h2>
                <p>Connect with friends in real-time</p>
            </div>
            
            <div id="loginForm" class="auth-form">
                <h4 class="text-center mb-4">Login</h4>
                <div class="mb-3">
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-user"></i></span>
                        <input type="text" id="loginUsername" class="form-control" placeholder="Username or Email">
                    </div>
                </div>
                <div class="mb-4">
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        <input type="password" id="loginPassword" class="form-control" placeholder="Password">
                    </div>
                </div>
                <button onclick="handleLogin()" class="btn btn-primary w-100 mb-3">
                    <i class="fas fa-sign-in-alt"></i> Login
                </button>
                <p class="text-center mb-0">
                    Don't have an account? 
                    <a href="#" onclick="showRegister()">Register here</a>
                </p>
            </div>
            
            <div id="registerForm" class="auth-form" style="display: none;">
                <h4 class="text-center mb-4">Register</h4>
                <div class="mb-3">
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-user"></i></span>
                        <input type="text" id="regFullName" class="form-control" placeholder="Full Name">
                    </div>
                </div>
                <div class="mb-3">
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-at"></i></span>
                        <input type="text" id="regUsername" class="form-control" placeholder="Username">
                    </div>
                </div>
                <div class="mb-3">
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-envelope"></i></span>
                        <input type="email" id="regEmail" class="form-control" placeholder="Email">
                    </div>
                </div>
                <div class="mb-4">
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-lock"></i></span>
                        <input type="password" id="regPassword" class="form-control" placeholder="Password">
                    </div>
                </div>
                <button onclick="handleRegister()" class="btn btn-success w-100 mb-3">
                    <i class="fas fa-user-plus"></i> Register
                </button>
                <p class="text-center mb-0">
                    Already have an account? 
                    <a href="#" onclick="showLogin()">Login here</a>
                </p>
            </div>
        </div>
    </div>

    <!-- Dashboard Section -->
    <div id="dashboardSection" class="dashboard app-container">
        <div class="chat-container">
            <!-- Sidebar -->
            <div class="sidebar" id="sidebar">
                <div class="user-profile">
                    <div class="user-info">
                        <img id="profilePic" src="uploads/default.jpg" alt="Profile" class="profile-pic">
                        <div class="user-details">
                            <h6 id="userFullName">Loading...</h6>
                            <small id="userUsername">@username</small>
                        </div>
                        <div class="action-buttons">
                            <button class="action-btn" onclick="showEditProfile()" title="Edit Profile">
                                <i class="fas fa-user-edit"></i>
                            </button>
                            <button class="action-btn" onclick="handleLogout()" title="Logout">
                                <i class="fas fa-sign-out-alt"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <div class="users-list" id="usersList">
                    <div class="text-center p-4">
                        <div class="loading"></div>
                        <p class="mt-2">Loading users...</p>
                    </div>
                </div>
            </div>
            
            <!-- Chat Area -->
            <div class="chat-area" id="chatArea">
                <div class="chat-header">
                    <div class="d-flex align-items-center">
                        <button class="back-btn" onclick="toggleSidebar()">
                            <i class="fas fa-arrow-left"></i>
                        </button>
                        <div id="selectedUserInfo">
                            <h5 class="mb-0">Select a user to chat</h5>
                        </div>
                    </div>
                    <div class="call-buttons" id="callButtons" style="display: none;">
                        <button class="call-btn audio-call" onclick="startCall('audio')">
                            <i class="fas fa-phone"></i> Audio
                        </button>
                        <button class="call-btn video-call" onclick="startCall('video')">
                            <i class="fas fa-video"></i> Video
                        </button>
                    </div>
                </div>
                
                <div class="messages-container" id="messagesContainer">
                    <div class="text-center p-5 text-muted">
                        <i class="fas fa-comments fa-3x mb-3"></i>
                        <h5>Select a conversation to start chatting</h5>
                    </div>
                </div>
                
                <div class="message-input-area" id="messageInputContainer" style="display: none;">
                    <form class="message-form" onsubmit="sendMessage(event)">
                        <input type="text" id="messageInput" class="message-input" placeholder="Type your message..." autocomplete="off">
                        <button type="submit" class="send-btn">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Edit Profile Modal -->
    <div class="modal fade" id="editProfileModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Edit Profile</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="profileForm" enctype="multipart/form-data">
                        <div class="text-center">
                            <img id="editProfilePic" src="uploads/default.jpg" alt="Profile" class="current-profile-pic">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Profile Picture</label>
                            <input type="file" class="form-control" id="profilePicInput" accept="image/*">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Full Name</label>
                            <input type="text" class="form-control" id="editFullName" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Email</label>
                            <input type="email" class="form-control" id="editEmail" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Bio</label>
                            <textarea class="form-control" id="editBio" rows="3"></textarea>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" onclick="updateProfile()">Save Changes</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Video Call Modal -->
    <div class="modal fade" id="videoCallModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-video"></i> Video Call
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="video-container">
                        <div class="video-box">
                            <video id="localVideo" autoplay muted playsinline></video>
                            <span class="video-label">You</span>
                        </div>
                        <div class="video-box">
                            <video id="remoteVideo" autoplay playsinline></video>
                            <span class="video-label" id="remoteVideoLabel">Remote User</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-danger" onclick="endCall()">
                        <i class="fas fa-phone-slash"></i> End Call
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast Notification Container -->
    <div id="toastContainer"></div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // ========== GLOBAL VARIABLES ==========
        let currentUser = null;
        let currentChatUser = null;
        let localStream = null;
        let peerConnection = null;
        let callInterval = null;
        
        // ========== AUTH FUNCTIONS ==========
        function showLogin() {
            document.getElementById('loginForm').style.display = 'block';
            document.getElementById('registerForm').style.display = 'none';
        }
        
        function showRegister() {
            document.getElementById('loginForm').style.display = 'none';
            document.getElementById('registerForm').style.display = 'block';
        }
        
        async function handleLogin() {
            const username = document.getElementById('loginUsername').value;
            const password = document.getElementById('loginPassword').value;
            
            if (!username || !password) {
                showToast('Please fill in all fields', 'error');
                return;
            }
            
            const formData = new FormData();
            formData.append('username', username);
            formData.append('password', password);
            
            try {
                const response = await fetch('?action=api_login', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                
                if (data.success) {
                    currentUser = data.user;
                    showToast('Login successful!', 'success');
                    document.getElementById('authSection').style.display = 'none';
                    document.getElementById('dashboardSection').style.display = 'block';
                    loadUserData();
                    startPolling();
                } else {
                    showToast(data.error, 'error');
                }
            } catch (error) {
                showToast('Login failed', 'error');
            }
        }
        
        async function handleRegister() {
            const fullName = document.getElementById('regFullName').value;
            const username = document.getElementById('regUsername').value;
            const email = document.getElementById('regEmail').value;
            const password = document.getElementById('regPassword').value;
            
            if (!fullName || !username || !email || !password) {
                showToast('Please fill in all fields', 'error');
                return;
            }
            
            const formData = new FormData();
            formData.append('full_name', fullName);
            formData.append('username', username);
            formData.append('email', email);
            formData.append('password', password);
            
            try {
                const response = await fetch('?action=api_register', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                
                if (data.success) {
                    showToast('Registration successful! Please login.', 'success');
                    showLogin();
                } else {
                    showToast(data.error, 'error');
                }
            } catch (error) {
                showToast('Registration failed', 'error');
            }
        }
        
        async function handleLogout() {
            try {
                await fetch('?action=api_logout');
                if (callInterval) clearInterval(callInterval);
                if (localStream) {
                    localStream.getTracks().forEach(track => track.stop());
                }
                document.getElementById('authSection').style.display = 'flex';
                document.getElementById('dashboardSection').style.display = 'none';
                showToast('Logged out successfully', 'success');
            } catch (error) {
                showToast('Logout failed', 'error');
            }
        }
        
        // ========== DASHBOARD FUNCTIONS ==========
        function loadUserData() {
            // Load current user data
            const user = currentUser;
            document.getElementById('userFullName').innerHTML = user.full_name || user.username;
            document.getElementById('userUsername').innerHTML = '@' + user.username;
            document.getElementById('profilePic').src = 'uploads/' + (user.profile_pic || 'default.jpg');
            
            // Load users list
            loadUsers();
        }
        
        async function loadUsers() {
            try {
                const response = await fetch('?action=api_get_users');
                const users = await response.json();
                
                if (users.error) {
                    showToast(users.error, 'error');
                    return;
                }
                
                const usersList = document.getElementById('usersList');
                usersList.innerHTML = '';
                
                users.forEach(user => {
                    const statusClass = user.status === 'online' ? 'status-online' : 'status-offline';
                    const unreadBadge = user.unread_count > 0 ? 
                        `<span class="unread-badge">${user.unread_count} new</span>` : '';
                    const lastMessage = user.last_message ? 
                        `<small class="text-muted d-block text-truncate">${user.last_message.substring(0, 30)}...</small>` : '';
                    
                    const userItem = document.createElement('div');
                    userItem.className = `user-item ${currentChatUser == user.id ? 'active' : ''}`;
                    userItem.onclick = () => selectUser(user.id, user.full_name, user.profile_pic);
                    userItem.innerHTML = `
                        <img src="uploads/${user.profile_pic || 'default.jpg'}" class="profile-pic">
                        <div style="margin-left: 15px; flex: 1;">
                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                <h6 style="margin: 0; font-weight: 600;">${user.full_name}</h6>
                                <small>@${user.username}</small>
                            </div>
                            <div style="display: flex; align-items: center;">
                                <span class="online-status ${statusClass}"></span>
                                <small>${user.status_text}</small>
                                ${unreadBadge}
                            </div>
                            ${lastMessage}
                        </div>
                    `;
                    usersList.appendChild(userItem);
                });
            } catch (error) {
                console.error('Load users error:', error);
            }
        }
        
        async function selectUser(userId, userName, profilePic) {
            currentChatUser = userId;
            
            // Update UI
            document.getElementById('selectedUserInfo').innerHTML = `
                <div class="d-flex align-items-center">
                    <img src="uploads/${profilePic || 'default.jpg'}" class="profile-pic me-2">
                    <div>
                        <h5 class="mb-0">${userName}</h5>
                    </div>
                </div>
            `;
            document.getElementById('messageInputContainer').style.display = 'block';
            document.getElementById('callButtons').style.display = 'flex';
            
            // Mark messages as read
            const formData = new FormData();
            formData.append('sender_id', userId);
            fetch('?action=api_mark_read', {
                method: 'POST',
                body: formData
            });
            
            // Load messages
            loadMessages(userId);
            
            // Mobile view
            if (window.innerWidth <= 768) {
                document.getElementById('sidebar').classList.remove('active');
                document.getElementById('chatArea').classList.add('active');
            }
        }
        
        async function loadMessages(userId) {
            try {
                const response = await fetch(`?action=api_get_messages&user_id=${userId}`);
                const messages = await response.json();
                
                const container = document.getElementById('messagesContainer');
                container.innerHTML = '';
                
                messages.forEach(msg => {
                    const isSent = msg.sender_id == currentUser.id;
                    const time = new Date(msg.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
                    const readIcon = msg.is_read ? 
                        '<i class="fas fa-check-double" style="color: #4CAF50;"></i>' : 
                        '<i class="fas fa-check"></i>';
                    
                    const messageDiv = document.createElement('div');
                    messageDiv.className = `message ${isSent ? 'sent' : 'received'}`;
                    
                    if (!isSent) {
                        messageDiv.innerHTML = `
                            <img src="uploads/${msg.profile_pic || 'default.jpg'}" class="profile-pic me-2">
                        `;
                    }
                    
                    messageDiv.innerHTML += `
                        <div class="message-content">
                            <div>${escapeHtml(msg.message)}</div>
                            <div class="message-time">
                                ${time} ${isSent ? readIcon : ''}
                            </div>
                        </div>
                    `;
                    
                    container.appendChild(messageDiv);
                });
                
                // Scroll to bottom
                container.scrollTop = container.scrollHeight;
            } catch (error) {
                console.error('Load messages error:', error);
            }
        }
        
        async function sendMessage(e) {
            e.preventDefault();
            const messageInput = document.getElementById('messageInput');
            const message = messageInput.value.trim();
            
            if (!message || !currentChatUser) return;
            
            const formData = new FormData();
            formData.append('receiver_id', currentChatUser);
            formData.append('message', message);
            
            try {
                const response = await fetch('?action=api_send_message', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                
                if (data.success) {
                    messageInput.value = '';
                    loadMessages(currentChatUser);
                    loadUsers(); // Update last message
                }
            } catch (error) {
                showToast('Failed to send message', 'error');
            }
        }
        
        // ========== PROFILE FUNCTIONS ==========
        function showEditProfile() {
            document.getElementById('editFullName').value = currentUser.full_name || '';
            document.getElementById('editEmail').value = currentUser.email || '';
            document.getElementById('editBio').value = currentUser.bio || '';
            document.getElementById('editProfilePic').src = 'uploads/' + (currentUser.profile_pic || 'default.jpg');
            
            const modal = new bootstrap.Modal(document.getElementById('editProfileModal'));
            modal.show();
        }
        
        async function updateProfile() {
            const formData = new FormData();
            formData.append('full_name', document.getElementById('editFullName').value);
            formData.append('email', document.getElementById('editEmail').value);
            formData.append('bio', document.getElementById('editBio').value);
            
            const fileInput = document.getElementById('profilePicInput');
            if (fileInput.files[0]) {
                formData.append('profile_pic', fileInput.files[0]);
            }
            
            try {
                const response = await fetch('?action=api_update_profile', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                
                if (data.success) {
                    showToast('Profile updated successfully!', 'success');
                    bootstrap.Modal.getInstance(document.getElementById('editProfileModal')).hide();
                    
                    // Refresh user data
                    const userResponse = await fetch('?action=api_get_users');
                    // Update current user display
                    location.reload(); // Simple reload to refresh all data
                }
            } catch (error) {
                showToast('Failed to update profile', 'error');
            }
        }
        
        // ========== CALL FUNCTIONS ==========
        async function startCall(type) {
            const modal = new bootstrap.Modal(document.getElementById('videoCallModal'));
            modal.show();
            
            try {
                localStream = await navigator.mediaDevices.getUserMedia({
                    video: type === 'video',
                    audio: true
                });
                
                document.getElementById('localVideo').srcObject = localStream;
                
                // Initialize peer connection
                const configuration = {
                    iceServers: [
                        { urls: 'stun:stun.l.google.com:19302' }
                    ]
                };
                
                peerConnection = new RTCPeerConnection(configuration);
                
                // Add local stream
                localStream.getTracks().forEach(track => {
                    peerConnection.addTrack(track, localStream);
                });
                
                // Handle remote stream
                peerConnection.ontrack = (event) => {
                    document.getElementById('remoteVideo').srcObject = event.streams[0];
                };
                
                // Handle ICE candidates
                peerConnection.onicecandidate = (event) => {
                    if (event.candidate) {
                        // Send ICE candidate to other peer
                        console.log('ICE candidate:', event.candidate);
                    }
                };
                
                // Create offer
                const offer = await peerConnection.createOffer();
                await peerConnection.setLocalDescription(offer);
                
                // Initiate call on server
                const formData = new FormData();
                formData.append('receiver_id', currentChatUser);
                formData.append('call_type', type);
                
                await fetch('?action=api_initiate_call', {
                    method: 'POST',
                    body: formData
                });
                
                showToast(`Starting ${type} call...`, 'info');
                
            } catch (error) {
                showToast('Could not access camera/microphone', 'error');
                console.error('Call error:', error);
            }
        }
        
        async function endCall() {
            if (localStream) {
                localStream.getTracks().forEach(track => track.stop());
            }
            
            if (peerConnection) {
                peerConnection.close();
            }
            
            const formData = new FormData();
            formData.append('receiver_id', currentChatUser);
            
            await fetch('?action=api_end_call', {
                method: 'POST',
                body: formData
            });
            
            bootstrap.Modal.getInstance(document.getElementById('videoCallModal')).hide();
            showToast('Call ended', 'info');
        }
        
        // ========== UTILITY FUNCTIONS ==========
        function escapeHtml(unsafe) {
            return unsafe
                .replace(/&/g, "&amp;")
                .replace(/</g, "&lt;")
                .replace(/>/g, "&gt;")
                .replace(/"/g, "&quot;")
                .replace(/'/g, "&#039;");
        }
        
        function showToast(message, type = 'info') {
            const toast = document.createElement('div');
            toast.className = `toast-notification toast-${type}`;
            toast.innerHTML = `
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-circle' : 'info-circle'}"></i>
                <span>${message}</span>
            `;
            
            document.getElementById('toastContainer').appendChild(toast);
            
            setTimeout(() => {
                toast.remove();
            }, 3000);
        }
        
        function startPolling() {
            // Poll for new messages and users every 2 seconds
            setInterval(() => {
                if (currentChatUser) {
                    loadMessages(currentChatUser);
                }
                loadUsers();
            }, 2000);
        }
        
        function toggleSidebar() {
            document.getElementById('sidebar').classList.add('active');
            document.getElementById('chatArea').classList.remove('active');
        }
        
        // Check login status on load
        window.onload = function() {
            // Check if user is already logged in via session
            <?php if (isLoggedIn()): ?>
                document.getElementById('authSection').style.display = 'none';
                document.getElementById('dashboardSection').style.display = 'block';
                currentUser = <?php echo json_encode(getUserById($_SESSION['user_id'])); ?>;
                loadUserData();
                startPolling();
            <?php endif; ?>
        };
    </script>
</body>
</html>
