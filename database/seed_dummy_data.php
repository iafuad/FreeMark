<?php
/**
 * FreeMark Dummy Data Seeder
 * Populates all tables with realistic, coherent test data for all features.
 * Safe and idempotent: can be executed multiple times without corrupting existing records.
 */

// Handle both CLI execution and web execution
$config_path = __DIR__ . '/../config/db.php';
if (!file_exists($config_path)) {
    die("Configuration file not found at $config_path\n");
}
require_once $config_path;

echo "========================================================\n";
echo "       FreeMark Comprehensive Database Seeder           \n";
echo "========================================================\n\n";

// Helper: Get or Create User
function seed_user($conn, $email, $password, $full_name, $role, $status = 'active') {
    $stmt = $conn->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    if ($existing) {
        return (int)$existing['id'];
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $ins = $conn->prepare("INSERT INTO users (email, password_hash, full_name, role, status) VALUES (?, ?, ?, ?, ?)");
    $ins->bind_param("sssss", $email, $hash, $full_name, $role, $status);
    $ins->execute();
    echo "  [+] User created: $email ($role, $status)\n";
    return (int)$ins->insert_id;
}

// Helper: Get or Create Client Profile
function seed_client_profile($conn, $user_id, $company_name, $hiring_volume, $bio) {
    $stmt = $conn->prepare("SELECT id FROM client_profiles WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    if ($existing) {
        return (int)$existing['id'];
    }

    $ins = $conn->prepare("INSERT INTO client_profiles (user_id, company_name, hiring_volume, bio) VALUES (?, ?, ?, ?)");
    $ins->bind_param("isss", $user_id, $company_name, $hiring_volume, $bio);
    $ins->execute();
    echo "  [+] Client Profile created: $company_name\n";
    return (int)$ins->insert_id;
}

// Helper: Get or Create Freelancer Profile
function seed_freelancer_profile($conn, $user_id, $title, $bio, $hourly_rate, $portfolio, $github) {
    $stmt = $conn->prepare("SELECT id FROM freelancer_profiles WHERE user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    if ($existing) {
        return (int)$existing['id'];
    }

    $ins = $conn->prepare("INSERT INTO freelancer_profiles (user_id, title, bio, hourly_rate, portfolio_link, github_link) VALUES (?, ?, ?, ?, ?, ?)");
    $ins->bind_param("issdss", $user_id, $title, $bio, $hourly_rate, $portfolio, $github);
    $ins->execute();
    echo "  [+] Freelancer Profile created: $title\n";
    return (int)$ins->insert_id;
}

// Helper: Get or Create Skill Category
function seed_category($conn, $name) {
    $stmt = $conn->prepare("SELECT id FROM skill_categories WHERE name = ?");
    $stmt->bind_param("s", $name);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    if ($existing) {
        return (int)$existing['id'];
    }

    $ins = $conn->prepare("INSERT INTO skill_categories (name) VALUES (?)");
    $ins->bind_param("s", $name);
    $ins->execute();
    echo "  [+] Skill Category added: $name\n";
    return (int)$ins->insert_id;
}

// Helper: Assign Skill to Freelancer
function seed_freelancer_skill($conn, $freelancer_id, $skill_id) {
    $stmt = $conn->prepare("INSERT IGNORE INTO freelancer_skills (freelancer_id, skill_id) VALUES (?, ?)");
    $stmt->bind_param("ii", $freelancer_id, $skill_id);
    $stmt->execute();
}

// ---------------------------------------------------------
// 1. Skill Categories
// ---------------------------------------------------------
echo "1. Checking & Seeding Skill Categories...\n";
$cat_frontend = seed_category($conn, 'Frontend Developer');
$cat_backend  = seed_category($conn, 'Backend Developer');
$cat_uiux     = seed_category($conn, 'UI/UX Design');
$cat_react    = seed_category($conn, 'React');
$cat_nodejs   = seed_category($conn, 'Node.js');
$cat_ml       = seed_category($conn, 'ML');
$cat_python   = seed_category($conn, 'Python & AI');
$cat_devops   = seed_category($conn, 'DevOps & Cloud');
$cat_mobile   = seed_category($conn, 'Mobile App Development');

// ---------------------------------------------------------
// 2. Client Accounts & Profiles
// ---------------------------------------------------------
echo "\n2. Seeding Client Accounts & Profiles...\n";
// Ensure existing client is active
$client1_uid = seed_user($conn, 'client@fincore.com', 'client123', 'FinCore Solutions', 'client', 'active');
$client1_pid = seed_client_profile($conn, $client1_uid, 'FinCore Solutions', '10-50', 'Leading fintech company developing high-volume digital banking and investment interfaces.');

// Additional Clients
$client2_uid = seed_user($conn, 'sarah@healthpulse.io', 'client123', 'Sarah Jenkins', 'client', 'active');
$client2_pid = seed_client_profile($conn, $client2_uid, 'HealthPulse Technologies', '10-50', 'Next-generation telemedicine and patient monitoring SaaS platform based in Boston, MA.');

$client3_uid = seed_user($conn, 'marcus@vividstudio.co', 'client123', 'Marcus Vance', 'client', 'active');
$client3_pid = seed_client_profile($conn, $client3_uid, 'Vivid Creative Labs', '1-10', 'Boutique digital product design and creative technology studio in New York, NY.');

$client4_uid = seed_user($conn, 'elena@cloudnest.dev', 'client123', 'Elena Rostova', 'client', 'active');
$client4_pid = seed_client_profile($conn, $client4_uid, 'CloudNest Infrastructure', '50+', 'Enterprise cloud management, Kubernetes orchestration, and automated DevOps platform.');

// Pending Client (To populate Admin Approvals queue!)
$client5_uid = seed_user($conn, 'kevin@apexretail.com', 'client123', 'Kevin O\'Connor', 'client', 'pending');
$client5_pid = seed_client_profile($conn, $client5_uid, 'Apex Retail Global', '10-50', 'Omnichannel retail software for real-time inventory and checkout sync.');

// ---------------------------------------------------------
// 3. Freelancer Accounts, Profiles & Skills
// ---------------------------------------------------------
echo "\n3. Seeding Freelancer Accounts, Profiles & Skills...\n";
// Existing Freelancers
$fl1_uid = seed_user($conn, 'sami@freemark.com', 'sami123', 'Md Sami', 'freelancer', 'active');
$fl1_pid = seed_freelancer_profile($conn, $fl1_uid, 'Senior Full Stack Developer', 'I build scalable, secure web applications with React, Node.js, and TypeScript. Over 6 years of fintech and SaaS experience.', 45.00, 'https://sami-portfolio.dev', 'https://github.com/mdsami-dev');
seed_freelancer_skill($conn, $fl1_pid, $cat_frontend);
seed_freelancer_skill($conn, $fl1_pid, $cat_backend);
seed_freelancer_skill($conn, $fl1_pid, $cat_react);
seed_freelancer_skill($conn, $fl1_pid, $cat_nodejs);

$fl2_uid = seed_user($conn, 'fuad@freemark.com', 'fuad123', 'Fuad', 'freelancer', 'suspended');
$fl2_pid = seed_freelancer_profile($conn, $fl2_uid, 'UI/UX Designer', 'Creating beautiful, intuitive user interfaces and wireframes.', 35.00, null, null);
seed_freelancer_skill($conn, $fl2_pid, $cat_uiux);

$fl3_uid = seed_user($conn, 'iafuad.bd@gmail.com', 'freelancer123', 'Iftekhar Alam', 'freelancer', 'active');
$fl3_pid = seed_freelancer_profile($conn, $fl3_uid, 'React & Frontend Specialist', 'Specializing in reactive UI architecture, high performance client state, and responsive design systems.', 50.00, 'https://fuad.pages.dev', 'https://github.com/iafuad');
seed_freelancer_skill($conn, $fl3_pid, $cat_frontend);
seed_freelancer_skill($conn, $fl3_pid, $cat_react);
seed_freelancer_skill($conn, $fl3_pid, $cat_uiux);

// Additional Top Freelancers
$fl4_uid = seed_user($conn, 'alexa.chen@freemark.com', 'freelancer123', 'Alexa Chen', 'freelancer', 'active');
$fl4_pid = seed_freelancer_profile($conn, $fl4_uid, 'Lead UI/UX & Product Designer', 'Designing human-centered digital experiences for SaaS, mobile apps, and design systems. Former design lead at Stripe.', 55.00, 'https://alexachen.design', 'https://github.com/alexachen-design');
seed_freelancer_skill($conn, $fl4_pid, $cat_uiux);
seed_freelancer_skill($conn, $fl4_pid, $cat_frontend);
seed_freelancer_skill($conn, $fl4_pid, $cat_mobile);

$fl5_uid = seed_user($conn, 'david.miller@freemark.com', 'freelancer123', 'David Miller', 'freelancer', 'active');
$fl5_pid = seed_freelancer_profile($conn, $fl5_uid, 'Senior Python & Machine Learning Engineer', 'Building robust machine learning pipelines, NLP microservices, and high-performance backend APIs using FastAPI and PyTorch.', 75.00, 'https://davidmiller.ai', 'https://github.com/davidmiller-ai');
seed_freelancer_skill($conn, $fl5_pid, $cat_python);
seed_freelancer_skill($conn, $fl5_pid, $cat_ml);
seed_freelancer_skill($conn, $fl5_pid, $cat_backend);

$fl6_uid = seed_user($conn, 'priya.patel@freemark.com', 'freelancer123', 'Priya Patel', 'freelancer', 'active');
$fl6_pid = seed_freelancer_profile($conn, $fl6_uid, 'Cloud DevOps & Site Reliability Architect', 'AWS Certified Solutions Architect with deep expertise in Kubernetes, Docker, Terraform, and automated zero-downtime CI/CD pipelines.', 65.00, 'https://priyapatel.cloud', 'https://github.com/priyapatel-cloud');
seed_freelancer_skill($conn, $fl6_pid, $cat_devops);
seed_freelancer_skill($conn, $fl6_pid, $cat_backend);
seed_freelancer_skill($conn, $fl6_pid, $cat_nodejs);

$fl7_uid = seed_user($conn, 'lucas.silva@freemark.com', 'freelancer123', 'Lucas Silva', 'freelancer', 'active');
$fl7_pid = seed_freelancer_profile($conn, $fl7_uid, 'Mobile Engineer (Flutter & React Native)', 'Crafting silky-smooth 60fps iOS and Android applications with offline-first state and native device integrations.', 50.00, 'https://lucassilva.mobile', 'https://github.com/lucassilva-dev');
seed_freelancer_skill($conn, $fl7_pid, $cat_mobile);
seed_freelancer_skill($conn, $fl7_pid, $cat_react);
seed_freelancer_skill($conn, $fl7_pid, $cat_frontend);

// Pending Freelancer (Populates Admin Approvals Queue!)
$fl8_uid = seed_user($conn, 'hassan.tariq@freemark.com', 'freelancer123', 'Hassan Tariq', 'freelancer', 'pending');
$fl8_pid = seed_freelancer_profile($conn, $fl8_uid, 'Junior Web Developer', 'Passionate junior developer eager to build clean interfaces with HTML, CSS, JavaScript, and PHP.', 25.00, 'https://hassantariq.me', 'https://github.com/hassantariq');
seed_freelancer_skill($conn, $fl8_pid, $cat_frontend);

// ---------------------------------------------------------
// 4. Projects (Open, In Progress, Completed, Closed)
// ---------------------------------------------------------
echo "\n4. Seeding Projects across Multiple Clients & Categories...\n";

function seed_project($conn, $client_id, $title, $description, $skill_category_id, $duration, $budget_type, $budget_max, $status) {
    $stmt = $conn->prepare("SELECT id FROM projects WHERE client_id = ? AND title = ?");
    $stmt->bind_param("is", $client_id, $title);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    if ($existing) {
        return (int)$existing['id'];
    }

    $ins = $conn->prepare("INSERT INTO projects (client_id, title, description, skill_category_id, duration, budget_type, budget_max, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $ins->bind_param("ississds", $client_id, $title, $description, $skill_category_id, $duration, $budget_type, $budget_max, $status);
    $ins->execute();
    echo "  [+] Project created: '$title' ($status)\n";
    return (int)$ins->insert_id;
}

// Open Projects (Available for freelancers to apply to on find jobs)
$proj_telehealth = seed_project(
    $conn, $client2_pid, 
    'Full-Stack Telehealth Patient Portal (React + Node.js)',
    'We require an experienced full-stack engineer to develop our HIPAA-compliant video appointment scheduler and patient record interface. Tech stack: React 18, Node.js/Express, Tailwind CSS, WebRTC.',
    $cat_react, '1_3m', 'fixed', 3200.00, 'open'
);

$proj_design_system = seed_project(
    $conn, $client3_pid,
    'Design System & Comprehensive UI Component Kit in Figma',
    'Seeking a top-tier UI/UX designer to craft a scalable design system for our multi-brand agency. Includes typography scale, responsive grid tokens, dark mode themes, and complete component variants.',
    $cat_uiux, '1_4w', 'fixed', 1800.00, 'open'
);

$proj_k8s_pipeline = seed_project(
    $conn, $client4_pid,
    'Automated Kubernetes CI/CD Pipeline & Monitoring Setup',
    'Looking for a DevOps specialist to architect GitOps deployments with ArgoCD, automate Docker builds with GitHub Actions, and configure Prometheus/Grafana cluster dashboards.',
    $cat_devops, '1_4w', 'hourly', 70.00, 'open'
);

$proj_ai_analytics = seed_project(
    $conn, $client2_pid,
    'Predictive Analytics & Patient Churn Model in Python',
    'Build and evaluate a machine learning classification model predicting patient follow-up adherence based on historical electronic health data. Deliverables include Python notebook and containerized REST endpoint.',
    $cat_python, '1_3m', 'fixed', 2400.00, 'open'
);

$proj_mobile_commerce = seed_project(
    $conn, $client3_pid,
    'Cross-Platform E-Commerce Mobile App in Flutter',
    'Need a mobile developer to build a modern iOS & Android retail shopping app with interactive cart, Stripe payment sheet integration, and push notifications.',
    $cat_mobile, '1_3m', 'fixed', 2900.00, 'open'
);

// In-Progress Projects (Linked to Active Contracts)
$proj_fincore_dash = seed_project(
    $conn, $client1_pid,
    'Build a Dashboard in React',
    'Looking for an experienced React dev to build a financial dashboard with real-time portfolio charts and transaction tables.',
    $cat_react, '1_4w', 'fixed', 1500.00, 'in_progress'
);

$proj_vivid_branding = seed_project(
    $conn, $client3_pid,
    'Fintech Brand Identity & Interactive Marketing Website',
    'Develop a high-impact promotional website with micro-animations and interactive product calculators for our financial services client.',
    $cat_frontend, '1_4w', 'fixed', 1800.00, 'in_progress'
);

// Completed Projects (Linked to Completed Contracts & Client Reviews)
$proj_cloud_migration = seed_project(
    $conn, $client4_pid,
    'Legacy AWS EC2 Migration to Containerized ECS Cluster',
    'Migrate 12 monolithic microservices from legacy virtual machines to containerized Docker tasks on Amazon ECS with Application Load Balancers.',
    $cat_devops, '1_4w', 'fixed', 2800.00, 'completed'
);

$proj_mobile_redesign = seed_project(
    $conn, $client1_pid,
    'Redesign Mobile App UI',
    'Complete UX audit and redesign of iOS investment application including user testing prototypes and developer handoff.',
    $cat_uiux, '1_3m', 'fixed', 2500.00, 'completed'
);

// Closed Project (Demonstrates closed state in reports & dashboards)
$proj_closed = seed_project(
    $conn, $client1_pid,
    'Internal Legacy PHP 5.6 to Modern Laravel Upgrade',
    'Project scope altered before contract initiation; archived by client.',
    $cat_backend, 'less_1w', 'fixed', 800.00, 'closed'
);

// ---------------------------------------------------------
// 5. Proposals (Pending, Accepted, Declined)
// ---------------------------------------------------------
echo "\n5. Seeding Proposals across Diverse Statuses...\n";

function seed_proposal($conn, $project_id, $freelancer_id, $cover_letter, $bid_amount, $duration, $status) {
    $stmt = $conn->prepare("SELECT id FROM proposals WHERE project_id = ? AND freelancer_id = ?");
    $stmt->bind_param("ii", $project_id, $freelancer_id);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    if ($existing) {
        return (int)$existing['id'];
    }

    $ins = $conn->prepare("INSERT INTO proposals (project_id, freelancer_id, cover_letter, bid_amount, estimated_duration, status) VALUES (?, ?, ?, ?, ?, ?)");
    $ins->bind_param("iisdss", $project_id, $freelancer_id, $cover_letter, $bid_amount, $duration, $status);
    $ins->execute();
    echo "  [+] Proposal added: Project #$project_id & Freelancer #$freelancer_id ($status)\n";
    return (int)$ins->insert_id;
}

// Pending Proposals on Open Projects (Populates Client Action Center: "Proposals Needing Review"!)
seed_proposal(
    $conn, $proj_telehealth, $fl1_pid,
    "Hello Sarah! I have built several HIPAA-compliant medical interfaces in React and Node.js with encrypted WebRTC video streaming. I can architect your telehealth patient portal with top security and accessibility standards.",
    3100.00, "4 weeks", "pending"
);

seed_proposal(
    $conn, $proj_telehealth, $fl3_pid,
    "Hi HealthPulse team! As a specialized React frontend developer, I can build clean, performant appointment scheduling and medical records views matching your design specifications.",
    3000.00, "3 weeks", "pending"
);

seed_proposal(
    $conn, $proj_design_system, $fl4_pid,
    "Hi Marcus! I specialize in building systematic, production-ready Figma design systems with automated token synchronization for React developers. I would love to lead this component kit for Vivid Creative Labs.",
    1800.00, "2 weeks", "pending"
);

seed_proposal(
    $conn, $proj_ai_analytics, $fl5_pid,
    "Hello! I have 7+ years of experience engineering predictive clinical models in Python (scikit-learn, XGBoost, PyTorch) with containerized deployment. I can deliver both the high-accuracy model and the production API.",
    2400.00, "3 weeks", "pending"
);

// Accepted Proposals (Tied to contracts)
seed_proposal(
    $conn, $proj_fincore_dash, $fl1_pid,
    "Hello! I am a senior developer with extensive experience building fintech dashboards and real-time chart interfaces. I can complete this project efficiently.",
    1500.00, "3 weeks", "accepted"
);

seed_proposal(
    $conn, $proj_vivid_branding, $fl4_pid,
    "Excited to partner on this fintech promotional build! I will provide custom UX illustrations, interactive pricing widgets, and clean responsive CSS.",
    1800.00, "3 weeks", "accepted"
);

seed_proposal(
    $conn, $proj_cloud_migration, $fl6_pid,
    "I have migrated over 30 enterprise workloads to AWS ECS with zero production downtime. I will automate your Terraform infrastructure and provide complete runbooks.",
    2800.00, "3 weeks", "accepted"
);

seed_proposal(
    $conn, $proj_mobile_redesign, $fl4_pid,
    "Completed complete UX audit and design sprints for iOS trading applications. Prepared to deliver high-fidelity prototypes.",
    2500.00, "4 weeks", "accepted"
);

// Declined Proposals (Demonstrates declined proposal status)
seed_proposal(
    $conn, $proj_design_system, $fl7_pid,
    "I can adapt your mobile UI kit directly to Flutter code.",
    2200.00, "4 weeks", "declined"
);

// ---------------------------------------------------------
// 6. Contracts & Multi-State Milestones
// ---------------------------------------------------------
echo "\n6. Seeding Contracts & Milestones (Approved, Submitted, Changes Requested, In Progress)...\n";

function seed_contract($conn, $project_id, $client_id, $freelancer_id, $total_budget, $paid_to_date, $status) {
    $stmt = $conn->prepare("SELECT id FROM contracts WHERE project_id = ? AND client_id = ? AND freelancer_id = ?");
    $stmt->bind_param("iii", $project_id, $client_id, $freelancer_id);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    if ($existing) {
        return (int)$existing['id'];
    }

    $ins = $conn->prepare("INSERT INTO contracts (project_id, client_id, freelancer_id, total_budget, paid_to_date, status) VALUES (?, ?, ?, ?, ?, ?)");
    $ins->bind_param("iiidds", $project_id, $client_id, $freelancer_id, $total_budget, $paid_to_date, $status);
    $ins->execute();
    echo "  [+] Contract created: Project #$project_id & Freelancer #$freelancer_id ($status)\n";
    return (int)$ins->insert_id;
}

function seed_milestone($conn, $contract_id, $title, $amount, $status, $submission_msg = null, $submission_link = null) {
    $stmt = $conn->prepare("SELECT id FROM milestones WHERE contract_id = ? AND title = ?");
    $stmt->bind_param("is", $contract_id, $title);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    if ($existing) {
        return (int)$existing['id'];
    }

    $sub_at = ($status === 'submitted' || $status === 'approved' || $status === 'changes_requested') ? date('Y-m-d H:i:s', strtotime('-1 day')) : null;
    $ins = $conn->prepare("INSERT INTO milestones (contract_id, title, amount, status, submission_message, submission_link, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $ins->bind_param("isdssss", $contract_id, $title, $amount, $status, $submission_msg, $submission_link, $sub_at);
    $ins->execute();
    echo "  [+] Milestone created: '$title' ($status)\n";
    return (int)$ins->insert_id;
}

// Contract 1: COMPLETED Contract (Full Payout, Linked to 5-Star Review!)
// Cloud Migration: Client 4 (CloudNest) -> Freelancer 6 (Priya Patel)
$contract_completed = seed_contract($conn, $proj_cloud_migration, $client4_pid, $fl6_pid, 2800.00, 2800.00, 'completed');
seed_milestone(
    $conn, $contract_completed, 
    "Milestone 1: Containerization & Dockerfile Optimization", 
    1200.00, 'approved',
    "All 12 microservices containerized with multi-stage builds. Average image size reduced by 65%.",
    "https://github.com/cloudnest-demo/infra/pull/14"
);
seed_milestone(
    $conn, $contract_completed, 
    "Milestone 2: ECS Cluster Deployment & Terraform Scripts", 
    1600.00, 'approved',
    "ECS tasks deployed across multiple availability zones with ALB routing and health checks fully automated via Terraform.",
    "https://github.com/cloudnest-demo/infra/pull/18"
);

// Contract 2: COMPLETED Contract (Full Payout, Linked to 5-Star Review!)
// Mobile Redesign: Client 1 (FinCore) -> Freelancer 4 (Alexa Chen)
$contract_completed2 = seed_contract($conn, $proj_mobile_redesign, $client1_pid, $fl4_pid, 2500.00, 2500.00, 'completed');
seed_milestone(
    $conn, $contract_completed2,
    "Milestone 1: Wireframes & UX Information Architecture",
    1000.00, 'approved',
    "Completed initial wireframes and card sorting analysis with stakeholders.",
    "https://figma.com/file/fincore-wireframes"
);
seed_milestone(
    $conn, $contract_completed2,
    "Milestone 2: High-Fidelity Design Prototype & Asset Export",
    1500.00, 'approved',
    "Final clickable iOS prototype completed with full component specs and developer redlines.",
    "https://figma.com/proto/fincore-ios-v2"
);

// Contract 3: ACTIVE Contract with SUBMITTED Milestone (Awaiting Client Approval!)
// Brand Identity: Client 3 (Vivid Creative) -> Freelancer 4 (Alexa Chen)
// Triggers Client Action Center "Milestones Needing Review" and client/work-approval.php!
$contract_submitted = seed_contract($conn, $proj_vivid_branding, $client3_pid, $fl4_pid, 1800.00, 800.00, 'active');
seed_milestone(
    $conn, $contract_submitted,
    "Milestone 1: Moodboards & Brand Direction Strategy",
    800.00, 'approved',
    "Presented 3 distinct creative directions. Direction B approved for execution.",
    "https://vividstudio.co/review/direction-b"
);
seed_milestone(
    $conn, $contract_submitted,
    "Milestone 2: Responsive Promotional Website Layouts",
    1000.00, 'submitted',
    "Completed the responsive desktop and mobile layouts for all 5 core marketing pages. Interactive calculators are fully functional for preview.",
    "https://vivid-preview.freemark.dev/fintech-landing"
);

// Contract 4: ACTIVE Contract with CHANGES REQUESTED Milestone!
// React Dashboard: Client 1 (FinCore) -> Freelancer 1 (Md Sami)
// Triggers Freelancer Dashboard "Revise Work" notification and freelancer/submit-milestone.php!
$contract_changes = seed_contract($conn, $proj_fincore_dash, $client1_pid, $fl1_pid, 1500.00, 600.00, 'active');
seed_milestone(
    $conn, $contract_changes,
    "Milestone 1: Authentication & Navigation Shell",
    600.00, 'approved',
    "Setup JWT authentication, persistent session storage, and responsive sidebar navigation.",
    "https://fincore-demo.freemark.io/auth"
);
seed_milestone(
    $conn, $contract_changes,
    "Milestone 2: Real-Time Portfolio Analytics Charts",
    900.00, 'changes_requested',
    "Please update the portfolio chart component to support 7-day, 30-day, and 1-year time ranges with animated transitions as outlined in milestone specs.",
    "https://fincore-demo.freemark.io/charts"
);

// ---------------------------------------------------------
// 7. Client Reviews (`reviews`)
// ---------------------------------------------------------
echo "\n7. Seeding Client Reviews & Ratings (5-Star & 4-Star)...\n";

function seed_review($conn, $contract_id, $client_id, $freelancer_id, $stars, $comment) {
    $stmt = $conn->prepare("SELECT id FROM reviews WHERE contract_id = ?");
    $stmt->bind_param("i", $contract_id);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    if ($existing) {
        return (int)$existing['id'];
    }

    $ins = $conn->prepare("INSERT INTO reviews (contract_id, client_id, freelancer_id, stars, comment) VALUES (?, ?, ?, ?, ?)");
    $ins->bind_param("iiiis", $contract_id, $client_id, $freelancer_id, $stars, $comment);
    $ins->execute();
    echo "  [+] Client Review created: $stars Stars for Freelancer #$freelancer_id\n";
    return (int)$ins->insert_id;
}

// 5-Star Review for Priya Patel (Cloud Migration)
seed_review(
    $conn, $contract_completed, $client4_pid, $fl6_pid, 5,
    "Exceptional attention to detail on our container migration. Priya automated our entire ECS cluster and CI/CD pipeline with zero downtime. Her technical documentation was top-tier. Will definitely hire again!"
);

// 5-Star Review for Alexa Chen (Mobile Redesign)
seed_review(
    $conn, $contract_completed2, $client1_pid, $fl4_pid, 5,
    "Alexa's mobile redesign completely transformed our user experience! Stakeholders and beta testers have praised the clean financial interface. User engagement has risen significantly. Outstanding partner."
);

// Additional Review for Md Sami (from earlier contract 1 if existing)
$prev_c1 = $conn->query("SELECT id, client_id, freelancer_id FROM contracts WHERE id = 1")->fetch_assoc();
if ($prev_c1) {
    seed_review(
        $conn, (int)$prev_c1['id'], (int)$prev_c1['client_id'], (int)$prev_c1['freelancer_id'], 5,
        "Sami delivered high quality code ahead of schedule. Very communicative, responsive to feedback, and solid mastery of React architecture."
    );
}

// ---------------------------------------------------------
// 8. Direct Job Invitations (`job_invitations`)
// ---------------------------------------------------------
echo "\n8. Seeding Direct Job Invitations (Pending, Accepted, Declined)...\n";

function seed_invitation($conn, $client_id, $freelancer_id, $project_id, $message, $status) {
    $stmt = $conn->prepare("SELECT id FROM job_invitations WHERE client_id = ? AND freelancer_id = ? AND project_id = ?");
    $stmt->bind_param("iii", $client_id, $freelancer_id, $project_id);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    if ($existing) {
        return (int)$existing['id'];
    }

    $ins = $conn->prepare("INSERT INTO job_invitations (client_id, freelancer_id, project_id, message, status) VALUES (?, ?, ?, ?, ?)");
    $ins->bind_param("iiiss", $client_id, $freelancer_id, $project_id, $message, $status);
    $ins->execute();
    echo "  [+] Job Invitation added: Client #$client_id -> Freelancer #$freelancer_id ($status)\n";
    return (int)$ins->insert_id;
}

// Pending Invitation: HealthPulse -> Alexa Chen (Populates Freelancer Dashboard Offer Action!)
seed_invitation(
    $conn, $client2_pid, $fl4_pid, $proj_telehealth,
    "Hello Alexa! We were deeply impressed by your design portfolio and verified credentials. We would love to invite you to review our telehealth project and submit an offer.",
    "pending"
);

// Pending Invitation: Vivid Creative -> Iftekhar Alam (Populates Iftekhar's Freelancer Dashboard Offer Action!)
seed_invitation(
    $conn, $client3_pid, $fl3_pid, $proj_design_system,
    "Hi Iftekhar! We are looking for an experienced React engineer to help turn our Figma design system into interactive components. Check out the project details!",
    "pending"
);

// Accepted Invitation: CloudNest -> Priya Patel
seed_invitation(
    $conn, $client4_pid, $fl6_pid, $proj_k8s_pipeline,
    "Hi Priya, we have an urgent requirement for Kubernetes cluster automation. Would love to have you on board.",
    "accepted"
);

// Declined Invitation: FinCore -> David Miller
seed_invitation(
    $conn, $client1_pid, $fl5_pid, $proj_ai_analytics,
    "Hi David, interested in your availability for a quick consulting engagement on data pipeline tuning.",
    "declined"
);

// ---------------------------------------------------------
// 9. Direct Messages (`messages` with Unread Indicators)
// ---------------------------------------------------------
echo "\n9. Seeding Direct Chat Conversations & Unread Messages...\n";

function seed_message($conn, $sender_id, $receiver_id, $content, $is_read, $mins_ago) {
    $stmt = $conn->prepare("SELECT id FROM messages WHERE sender_id = ? AND receiver_id = ? AND content = ?");
    $stmt->bind_param("iis", $sender_id, $receiver_id, $content);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    if ($existing) {
        return (int)$existing['id'];
    }

    $time = date('Y-m-d H:i:s', strtotime("-$mins_ago minutes"));
    $ins = $conn->prepare("INSERT INTO messages (sender_id, receiver_id, content, is_read, created_at) VALUES (?, ?, ?, ?, ?)");
    $ins->bind_param("iisis", $sender_id, $receiver_id, $content, $is_read, $time);
    $ins->execute();
    return (int)$ins->insert_id;
}

// Conversation: Sarah Jenkins (HealthPulse, uid: client2) <-> Alexa Chen (uid: fl4)
seed_message($conn, $client2_uid, $fl4_uid, "Hi Alexa! Thank you for reviewing our invitation for the Telehealth Patient Portal.", 1, 120);
seed_message($conn, $fl4_uid, $client2_uid, "Hello Sarah! Yes, I read through the scope. The WebRTC video integration looks very exciting.", 1, 90);
seed_message($conn, $client2_uid, $fl4_uid, "Could you share an estimate for the initial Figma design sprint when you have a moment?", 0, 15); // UNREAD for Alexa!

// Conversation: Marcus Vance (Vivid Creative, uid: client3) <-> Md Sami (uid: fl1)
seed_message($conn, $client3_uid, $fl1_uid, "Hey Sami, how is progress coming along on the promotional website calculator?", 1, 180);
seed_message($conn, $fl1_uid, $client3_uid, "Going great Marcus! I hooked up the real-time interest calculator and it runs smoothly.", 1, 140);
seed_message($conn, $client3_uid, $fl1_uid, "Awesome, looking forward to testing the staging link today!", 0, 25); // UNREAD for Sami!

// Conversation: Elena Rostova (CloudNest, uid: client4) <-> Priya Patel (uid: fl6)
seed_message($conn, $client4_uid, $fl6_uid, "Priya, the ECS cluster deployment passed all load tests with flying colors. Superb job!", 1, 300);
seed_message($conn, $fl6_uid, $client4_uid, "Thank you Elena! It was a pleasure working with your infrastructure team.", 1, 280);

// Conversation: FinCore (uid: 2) <-> Iftekhar Alam (uid: 5)
seed_message($conn, $client1_uid, $fl3_uid, "Hi Iftekhar, we saw your React portfolio. Would you be available for some frontend consulting next week?", 0, 40); // UNREAD for Iftekhar!

// ---------------------------------------------------------
// 10. Benchmark Quizzes, Questions & Verified Test Results
// ---------------------------------------------------------
echo "\n10. Seeding Benchmark Quizzes, Questions & Verified Badges...\n";

function seed_quiz($conn, $title, $description, $passing_score, $questions) {
    $stmt = $conn->prepare("SELECT id FROM quizzes WHERE title = ?");
    $stmt->bind_param("s", $title);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    if ($existing) {
        $quiz_id = (int)$existing['id'];
    } else {
        $ins = $conn->prepare("INSERT INTO quizzes (title, description, passing_score) VALUES (?, ?, ?)");
        $ins->bind_param("ssi", $title, $description, $passing_score);
        $ins->execute();
        $quiz_id = (int)$ins->insert_id;
        echo "  [+] Quiz created: '$title'\n";
    }

    // Add questions and options if not existing
    foreach ($questions as $q_data) {
        $q_stmt = $conn->prepare("SELECT id FROM quiz_questions WHERE quiz_id = ? AND question_text = ?");
        $q_stmt->bind_param("is", $quiz_id, $q_data['question']);
        $q_stmt->execute();
        $q_res = $q_stmt->get_result()->fetch_assoc();
        if ($q_res) {
            $question_id = (int)$q_res['id'];
        } else {
            $q_ins = $conn->prepare("INSERT INTO quiz_questions (quiz_id, question_text) VALUES (?, ?)");
            $q_ins->bind_param("is", $quiz_id, $q_data['question']);
            $q_ins->execute();
            $question_id = (int)$q_ins->insert_id;

            foreach ($q_data['options'] as $opt) {
                $o_ins = $conn->prepare("INSERT INTO question_options (question_id, option_text, is_correct) VALUES (?, ?, ?)");
                $o_ins->bind_param("isi", $question_id, $opt['text'], $opt['is_correct']);
                $o_ins->execute();
            }
        }
    }

    return $quiz_id;
}

// 1. Node.js & Backend Architecture Benchmark
$quiz_node = seed_quiz(
    $conn,
    'Node.js & Backend Architecture Benchmark',
    'Assess your understanding of the Node.js event loop, asynchronous concurrency, REST API design, and security best practices.',
    70,
    [
        [
            'question' => 'How does Node.js handle non-blocking asynchronous I/O operations under the hood?',
            'options' => [
                ['text' => 'Using the Libuv event loop and thread pool', 'is_correct' => 1],
                ['text' => 'By spawning a new operating system process for each incoming request', 'is_correct' => 0],
                ['text' => 'Through synchronous blocking CPU threads', 'is_correct' => 0],
                ['text' => 'Using a multi-threaded virtual memory manager', 'is_correct' => 0]
            ]
        ],
        [
            'question' => 'Which HTTP status code should be returned when an incoming request succeeds and a new resource is created?',
            'options' => [
                ['text' => '200 OK', 'is_correct' => 0],
                ['text' => '201 Created', 'is_correct' => 1],
                ['text' => '204 No Content', 'is_correct' => 0],
                ['text' => '202 Accepted', 'is_correct' => 0]
            ]
        ],
        [
            'question' => 'Which middleware is commonly used in Express to secure HTTP response headers against common vulnerabilities?',
            'options' => [
                ['text' => 'Helmet', 'is_correct' => 1],
                ['text' => 'Bcrypt', 'is_correct' => 0],
                ['text' => 'Multer', 'is_correct' => 0],
                ['text' => 'Nodemailer', 'is_correct' => 0]
            ]
        ],
        [
            'question' => 'What is the purpose of Node.js Streams?',
            'options' => [
                ['text' => 'Handling continuous read/write data in chunks without buffering entire files in RAM', 'is_correct' => 1],
                ['text' => 'Connecting to relational SQL database engines only', 'is_correct' => 0],
                ['text' => 'Compiling JavaScript down to native machine code at runtime', 'is_correct' => 0],
                ['text' => 'Replacing Promises in asynchronous control flow', 'is_correct' => 0]
            ]
        ]
    ]
);

// 2. Python & Data Engineering Benchmark
$quiz_python = seed_quiz(
    $conn,
    'Python & Machine Learning Foundations',
    'Evaluate foundational proficiency in Python data structures, pandas data manipulation, and model evaluation metrics.',
    70,
    [
        [
            'question' => 'In Python, which built-in data type is mutable and maintains ordered key-value pairs as of Python 3.7+?',
            'options' => [
                ['text' => 'Dictionary (dict)', 'is_correct' => 1],
                ['text' => 'Tuple', 'is_correct' => 0],
                ['text' => 'Set', 'is_correct' => 0],
                ['text' => 'FrozenSet', 'is_correct' => 0]
            ]
        ],
        [
            'question' => 'Which metric is best suited for evaluating a binary classification model on a heavily imbalanced dataset?',
            'options' => [
                ['text' => 'Precision-Recall AUC / F1-Score', 'is_correct' => 1],
                ['text' => 'Raw Accuracy', 'is_correct' => 0],
                ['text' => 'Mean Absolute Error (MAE)', 'is_correct' => 0],
                ['text' => 'R-Squared Score', 'is_correct' => 0]
            ]
        ],
        [
            'question' => 'Which library provides vector math and multidimensional arrays as the foundation for modern machine learning in Python?',
            'options' => [
                ['text' => 'NumPy', 'is_correct' => 1],
                ['text' => 'Requests', 'is_correct' => 0],
                ['text' => 'Flask', 'is_correct' => 0],
                ['text' => 'Pydantic', 'is_correct' => 0]
            ]
        ],
        [
            'question' => 'What is the primary benefit of Python generators (using the yield keyword)?',
            'options' => [
                ['text' => 'Lazy evaluation that produces items on demand with minimal memory footprint', 'is_correct' => 1],
                ['text' => 'Enforcing static type checking at compilation time', 'is_correct' => 0],
                ['text' => 'Allowing multi-threaded CPU parallel computation bypassing GIL', 'is_correct' => 0],
                ['text' => 'Encrypting return values for secure network transmission', 'is_correct' => 0]
            ]
        ]
    ]
);

// Populate Realistic Test Results & Verified Badges across Freelancers
// Note: test_results.passed is STORED GENERATED based on (score/max_score*100 >= 70)
function seed_test_result($conn, $freelancer_id, $quiz_id, $score, $max_score) {
    $stmt = $conn->prepare("SELECT id FROM test_results WHERE freelancer_id = ? AND quiz_id = ? AND score = ?");
    $stmt->bind_param("iii", $freelancer_id, $quiz_id, $score);
    $stmt->execute();
    $existing = $stmt->get_result()->fetch_assoc();
    if ($existing) {
        return (int)$existing['id'];
    }

    $ins = $conn->prepare("INSERT INTO test_results (freelancer_id, quiz_id, score, max_score) VALUES (?, ?, ?, ?)");
    $ins->bind_param("iiii", $freelancer_id, $quiz_id, $score, $max_score);
    $ins->execute();
    $pct = round(($score / $max_score) * 100);
    echo "  [+] Test Result recorded: Freelancer #$freelancer_id on Quiz #$quiz_id -> $score/$max_score ($pct%)\n";
    return (int)$ins->insert_id;
}

// Md Sami: Verified in UI/UX & React
seed_test_result($conn, $fl1_pid, 1, 2, 2); // 100% Passed -> Verified
seed_test_result($conn, $fl1_pid, 2, 3, 3); // 100% Passed -> Verified

// Alexa Chen: Verified in UI/UX
seed_test_result($conn, $fl4_pid, 1, 2, 2); // 100% Passed -> Verified

// David Miller: Verified in Python & ML
seed_test_result($conn, $fl5_pid, $quiz_python, 4, 4); // 100% Passed -> Verified

// Priya Patel: Verified in Node.js Backend Architecture
seed_test_result($conn, $fl6_pid, $quiz_node, 4, 4); // 100% Passed -> Verified

// Iftekhar Alam: Verified in React & Node.js
seed_test_result($conn, $fl3_pid, 2, 3, 3); // 100% Passed -> Verified
seed_test_result($conn, $fl3_pid, $quiz_node, 3, 4); // 75% Passed -> Verified

// Lucas Silva: Attempted React test with retake needed (Score 1/3 = 33% < 70%)
// Demonstrates the "Needs Retake" warning badge on freelancer/tests.php!
seed_test_result($conn, $fl7_pid, 2, 1, 3);

echo "\n========================================================\n";
echo "      Database Seeding Successfully Completed!         \n";
echo "========================================================\n";
