-- FreeMark XAMPP Demo Schema

DROP DATABASE IF EXISTS freemark;
CREATE DATABASE freemark;
USE freemark;

-- 1. users
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(100) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    role ENUM('admin', 'client', 'freelancer') NOT NULL,
    status ENUM('pending', 'active', 'suspended') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. client_profiles
CREATE TABLE client_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    company_name VARCHAR(100),
    hiring_volume ENUM('1-10', '10-50', '50+') DEFAULT '1-10',
    bio TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 3. freelancer_profiles
CREATE TABLE freelancer_profiles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(150),
    bio TEXT,
    hourly_rate DECIMAL(10,2),
    portfolio_link VARCHAR(255),
    github_link VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 4. skill_categories
CREATE TABLE skill_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) UNIQUE NOT NULL
);

-- 5. freelancer_skills (Pivot)
CREATE TABLE freelancer_skills (
    freelancer_id INT NOT NULL,
    skill_id INT NOT NULL,
    PRIMARY KEY (freelancer_id, skill_id),
    FOREIGN KEY (freelancer_id) REFERENCES freelancer_profiles(id) ON DELETE CASCADE,
    FOREIGN KEY (skill_id) REFERENCES skill_categories(id) ON DELETE CASCADE
);

-- 6. projects (Jobs posted by clients)
CREATE TABLE projects (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    skill_category_id INT,
    duration ENUM('less_1w', '1_4w', '1_3m', '3m_plus') DEFAULT '1_4w',
    budget_type ENUM('fixed', 'hourly') DEFAULT 'fixed',
    budget_max DECIMAL(10,2) NOT NULL,
    status ENUM('open', 'in_progress', 'completed', 'closed') DEFAULT 'open',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES client_profiles(id) ON DELETE CASCADE,
    FOREIGN KEY (skill_category_id) REFERENCES skill_categories(id) ON DELETE SET NULL
);

-- 7. proposals (Freelancer applications)
CREATE TABLE proposals (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    freelancer_id INT NOT NULL,
    cover_letter TEXT,
    bid_amount DECIMAL(10,2) NOT NULL,
    estimated_duration VARCHAR(50),
    status ENUM('pending', 'accepted', 'declined') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (freelancer_id) REFERENCES freelancer_profiles(id) ON DELETE CASCADE,
    UNIQUE(project_id, freelancer_id)
);

-- 8. contracts (Active work engagements)
CREATE TABLE contracts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    project_id INT NOT NULL,
    client_id INT NOT NULL,
    freelancer_id INT NOT NULL,
    total_budget DECIMAL(10,2) NOT NULL,
    paid_to_date DECIMAL(10,2) DEFAULT 0.00,
    status ENUM('active', 'completed', 'cancelled') DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    FOREIGN KEY (client_id) REFERENCES client_profiles(id) ON DELETE CASCADE,
    FOREIGN KEY (freelancer_id) REFERENCES freelancer_profiles(id) ON DELETE CASCADE
);

-- 9. milestones (Contract deliverables)
CREATE TABLE milestones (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NOT NULL,
    title VARCHAR(150) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    status ENUM('in_progress', 'submitted', 'changes_requested', 'approved') DEFAULT 'in_progress',
    submission_message TEXT,
    submission_link VARCHAR(255),
    submitted_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE
);

-- 10. reviews (Client feedback on freelancers)
CREATE TABLE reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    contract_id INT NOT NULL,
    client_id INT NOT NULL,
    freelancer_id INT NOT NULL,
    stars INT NOT NULL CHECK(stars BETWEEN 1 AND 5),
    comment TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (contract_id) REFERENCES contracts(id) ON DELETE CASCADE,
    FOREIGN KEY (client_id) REFERENCES client_profiles(id) ON DELETE CASCADE,
    FOREIGN KEY (freelancer_id) REFERENCES freelancer_profiles(id) ON DELETE CASCADE
);

-- 11. job_invitations (Direct client offers)
CREATE TABLE job_invitations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    freelancer_id INT NOT NULL,
    project_id INT NULL,
    message TEXT,
    status ENUM('pending', 'accepted', 'declined') DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (client_id) REFERENCES client_profiles(id) ON DELETE CASCADE,
    FOREIGN KEY (freelancer_id) REFERENCES freelancer_profiles(id) ON DELETE CASCADE,
    FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL
);

-- 12. messages (Simple Chat)
CREATE TABLE messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    content TEXT NOT NULL,
    is_read BOOLEAN DEFAULT FALSE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
);

-- 13. quizzes
CREATE TABLE quizzes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(150) NOT NULL,
    description TEXT,
    passing_score INT DEFAULT 70,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 14. quiz_questions
CREATE TABLE quiz_questions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    quiz_id INT NOT NULL,
    question_text TEXT NOT NULL,
    FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE
);

-- 15. question_options
CREATE TABLE question_options (
    id INT AUTO_INCREMENT PRIMARY KEY,
    question_id INT NOT NULL,
    option_text TEXT NOT NULL,
    is_correct BOOLEAN DEFAULT FALSE,
    FOREIGN KEY (question_id) REFERENCES quiz_questions(id) ON DELETE CASCADE
);

-- 16. test_results
CREATE TABLE test_results (
    id INT AUTO_INCREMENT PRIMARY KEY,
    freelancer_id INT NOT NULL,
    quiz_id INT NOT NULL,
    score INT NOT NULL,
    max_score INT NOT NULL,
    passed BOOLEAN GENERATED ALWAYS AS ((score / max_score) * 100 >= 70) STORED,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (freelancer_id) REFERENCES freelancer_profiles(id) ON DELETE CASCADE,
    FOREIGN KEY (quiz_id) REFERENCES quizzes(id) ON DELETE CASCADE
);

-- ========== SEED DATA ==========

-- Users
INSERT INTO users (id, email, password_hash, full_name, role, status) VALUES
(1, 'admin@freemark.com', '$2y$10$nePxMkwR6.xpD7uC9pxNG.AKdIWSMVy/CXFJD6Xrxix5UdfrqDv/e', 'Admin', 'admin', 'active'),
(2, 'client@fincore.com', '$2y$10$KWwryCclvq97.h..C.L0jeW9prxl.XORrs54JZ0lDfYlh4P5MQt4y', 'FinCore Solutions', 'client', 'active'),
(3, 'sami@freemark.com', '$2y$10$lmU/waRKmmxN5E05cVsVq.gwK7m8jApEryzOL2.gnty0sJ8va7D..', 'Md Sami', 'freelancer', 'active'),
(4, 'fuad@freemark.com', '$2y$10$ouVrrJ9sKR2.gudeuO4YHeZaUqeDdkf7guKvcgdFNDvN0.74Qd45i', 'Fuad', 'freelancer', 'active');

-- Client Profiles
INSERT INTO client_profiles (id, user_id, company_name, hiring_volume, bio) VALUES
(1, 2, 'FinCore Solutions', '10-50', 'We are a leading fintech company looking for top talent.');

-- Freelancer Profiles
INSERT INTO freelancer_profiles (id, user_id, title, bio, hourly_rate) VALUES
(1, 3, 'Senior Full Stack Developer', 'I build scalable web applications with React and Node.js.', 45.00),
(2, 4, 'UI/UX Designer', 'Creating beautiful and intuitive user experiences.', 35.00);

-- Skill Categories
INSERT INTO skill_categories (id, name) VALUES
(1, 'Frontend Developer'),
(2, 'Backend Developer'),
(3, 'UI/UX Design'),
(4, 'React'),
(5, 'Node.js'),
(6, 'ML');

-- Freelancer Skills
INSERT INTO freelancer_skills (freelancer_id, skill_id) VALUES
(1, 1), (1, 2), (1, 4), (1, 5),
(2, 3);

-- Projects
INSERT INTO projects (id, client_id, title, description, skill_category_id, duration, budget_type, budget_max, status) VALUES
(1, 1, 'Build a Dashboard in React', 'Looking for an experienced React dev to build a financial dashboard.', 4, '1_4w', 'fixed', 1500.00, 'open'),
(2, 1, 'Redesign Mobile App UI', 'Need a complete redesign of our iOS app.', 3, '1_3m', 'fixed', 2500.00, 'open');

-- Quizzes
INSERT INTO quizzes (id, title, description, passing_score) VALUES
(1, 'UI/UX Principles Test', 'Test your knowledge of core UI/UX concepts.', 70),
(2, 'React Fundamentals Test', 'Assess your basic understanding of React hooks and components.', 70);

-- Quiz Questions & Options
INSERT INTO quiz_questions (id, quiz_id, question_text) VALUES
(1, 1, 'What does UX stand for?'),
(2, 1, 'Which color model is best for digital screens?'),
(3, 2, 'Which hook is used for managing state in a functional component?');

INSERT INTO question_options (question_id, option_text, is_correct) VALUES
(1, 'User Experience', 1), (1, 'User Example', 0), (1, 'Uniform Experience', 0),
(2, 'CMYK', 0), (2, 'RGB', 1), (2, 'Pantone', 0),
(3, 'useEffect', 0), (3, 'useState', 1), (3, 'useContext', 0);

-- Proposals, Contracts, Messages (Empty initial state or light seed)
INSERT INTO test_results (freelancer_id, quiz_id, score, max_score) VALUES
(2, 1, 2, 2);
