README.md
markdown
# Complete PHP Chat Application

A feature-rich, real-time chat application built with PHP, SQLite, and JavaScript. This single-file application includes user authentication, private messaging, video/audio calls, profile management, and offline message support.

![Chat Application Preview](https://via.placeholder.com/800x400?text=Chat+Application+Preview)

## 🚀 Features

### 💬 Messaging
- **Real-time private messaging** - Instant message delivery
- **Offline messaging** - Messages are stored and delivered when users come online
- **Read receipts** - See when your messages are delivered and read
- **Message history** - Complete chat history preserved
- **Typing indicators** - See when someone is typing (optional)

### 📞 Voice & Video Calls
- **Peer-to-peer video calls** - HD video calling using WebRTC
- **Audio-only calls** - Voice calls when video isn't needed
- **Multi-platform** - Works on desktop and mobile browsers

### 👤 User Management
- **User registration** - Create new accounts
- **Secure login** - Password hashing with bcrypt
- **Profile editing** - Update personal information
- **Profile pictures** - Upload and change avatar
- **Bio/Status** - Set your status message

### 🎨 User Experience
- **Online/offline status** - See who's available
- **Last seen** - Know when users were last active
- **Unread message counter** - Never miss a message
- **Responsive design** - Works on all devices
- **Toast notifications** - Get notified of important events
- **Mobile-friendly** - Optimized for touch screens

### 🔒 Security
- **Password hashing** - Secure password storage
- **SQL injection prevention** - Prepared statements
- **XSS protection** - HTML escaping
- **File upload validation** - Secure image uploads
- **Session management** - Secure authentication

## 📋 Requirements

- PHP 7.4 or higher
- SQLite3 PHP extension
- PDO PHP extension
- OpenSSL PHP extension
- FileInfo PHP extension
- Web browser with WebRTC support (Chrome, Firefox, Safari, Edge)

## ⚡ Quick Installation

### Method 1: Direct Download

1. **Create a new directory:**
```bash
mkdir chat-app
cd chat-app
Create the main file:

bash
touch index.php
Copy the entire application code from the provided single-file solution into index.php

Start PHP server:

bash
php -S localhost:8000
Open your browser:

text
http://localhost:8000
Method 2: Using Git
bash
git clone https://github.com/yourusername/chat-app.git
cd chat-app
php -S localhost:8000
📁 Project Structure
text
chat-app/
│
├── index.php              # Main application file (everything in one file)
├── chat_app.db            # SQLite database (auto-generated)
├── uploads/               # Profile pictures directory (auto-created)
│   ├── default.jpg        # Default profile picture
│   └── [user_uploads]     # User uploaded profile pictures
└── README.md              # This file
🎯 Usage Guide
1. Registration
Open the application in your browser

Click "Register here"

Fill in your details:

Full Name

Username

Email

Password

Click "Register"

2. Login
Enter your username/email and password

Click "Login"

You'll be redirected to the dashboard

3. Start Chatting
View the list of online users on the left sidebar

Click on any user to start chatting

Type your message and press Enter or click send

Messages are delivered instantly or when user comes online

4. Make Calls
Select a user to chat with

Click "Audio" or "Video" button

Grant camera/microphone permissions

Enjoy your call!

5. Edit Profile
Click the profile icon in the top-left

Select "Edit Profile"

Update your information

Upload a new profile picture

Click "Save Changes"
