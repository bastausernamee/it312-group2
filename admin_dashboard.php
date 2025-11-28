<?php
include('db.php');
session_start();

// --- Admin authentication check ---
if (!isset($_SESSION['user_id']) || $_SESSION['username'] !== 'admin') {
    header("Location: login.php");
    exit;
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'phpmailer/src/PHPMailer.php';
require 'phpmailer/src/SMTP.php';
require 'phpmailer/src/Exception.php';

$username = $_SESSION['username'];

if (isset($_POST['ajax']) && $_POST['ajax'] == 1) {
    $filter = $_POST['filter'] ?? 'all';
    $where = "WHERE 1"; // <-- select all complaints

    switch ($filter) {
        case 'today':
            $where .= " AND DATE(created_at) = CURDATE()";
            break;
        case 'week':
            $where .= " AND YEARWEEK(created_at, 1) = YEARWEEK(CURDATE(), 1)";
            break;
        case 'month':
            $where .= " AND MONTH(created_at) = MONTH(CURDATE()) AND YEAR(created_at) = YEAR(CURDATE())";
            break;
        case 'all':
        default:
            break;
    }

    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END),0) AS pending,
            COALESCE(SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END),0) AS in_progress,
            COALESCE(SUM(CASE WHEN status = 'Resolved' THEN 1 ELSE 0 END),0) AS resolved
        FROM complaints
        $where
    ");

    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();

    echo json_encode($data);
    exit;
}

$stats = [
    "total" => 0,
    "pending" => 0,
    "resolved" => 0,
    "in_progress" => 0
];

$stmt = $conn->prepare("
    SELECT 
        COUNT(*) AS total,
        COALESCE(SUM(CASE WHEN status = 'Pending' THEN 1 ELSE 0 END), 0) AS pending,
        COALESCE(SUM(CASE WHEN status = 'Resolved' THEN 1 ELSE 0 END), 0) AS resolved,
        COALESCE(SUM(CASE WHEN status = 'In Progress' THEN 1 ELSE 0 END), 0) AS in_progress
    FROM complaints
    WHERE 1
");

$stmt->execute();
$result = $stmt->get_result();

if ($result && $result->num_rows > 0) {
    $stats = $result->fetch_assoc();
}

// --- Decrypt function ---
function decrypt_data($encrypted) {
    $key = "mysecretkey12345"; // Must match encryption key
    return openssl_decrypt($encrypted, "AES-128-ECB", $key);
}

// --- Handle complaint status update ---
if (isset($_POST['update_status'])) {
    $complaint_id = $_POST['complaint_id'];
    $new_status = $_POST['status'];

    // --- Get current status first ---
    $check = $conn->prepare("SELECT status FROM complaints WHERE id = ?");
    $check->bind_param("i", $complaint_id);
    $check->execute();
    $current = $check->get_result()->fetch_assoc();
    $check->close();

    // --- If same status, do NOT update ---
    if ($current && $current['status'] === $new_status) {
        $_SESSION['toastMessage'] = "No changes made. The status is already '$new_status'.";
        header("Location: admin_dashboard.php");
        exit;
    }

    // --- Proceed with update ---
    $stmt = $conn->prepare("UPDATE complaints SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $new_status, $complaint_id);
    $stmt->execute();
    $stmt->close();

    // Log action with admin's user_id
    $admin_id = $_SESSION['user_id'];

    $log = $conn->prepare("INSERT INTO activity_log (user_id, action, timestamp) VALUES (?, ?, NOW())");
    $action = "Updated Complaint Status (Complaint ID: $complaint_id)";
    $log->bind_param("is", $admin_id, $action);
    $log->execute();
    $log->close();


    // --- Fetch user info ---
    $userQuery = $conn->prepare("
        SELECT u.id, u.email_encrypted, u.username_encrypted
        FROM complaints c
        JOIN users u ON c.user_id = u.id
        WHERE c.id = ?
    ");
    $userQuery->bind_param("i", $complaint_id);
    $userQuery->execute();
    $user = $userQuery->get_result()->fetch_assoc();
    $userQuery->close();

    if ($user) {
        $email = decrypt_data($user['email_encrypted']);
        $username = decrypt_data($user['username_encrypted']);
        $message = "Hello $username, your complaint <strong>ID: $complaint_id</strong> has been updated to <strong>$new_status</strong>.";

        // Send email
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = '23-69926@g.batstate-u.edu.ph';
        $mail->Password = 'vcoi ufnq duxh pnyn';
        $mail->SMTPSecure = 'ssl';
        $mail->Port = 465;

        $mail->setFrom('23-69926@g.batstate-u.edu.ph');
        $mail->addAddress($email);

        $mail->isHTML(true);
        $mail->Subject = "Complaint Status Updated";
        $mail->Body = ($message);

        $mail->send();
    }

    $_SESSION['toastMessage'] = "Complaint ID #$complaint_id has been updated to '$new_status'.";
    header("Location: admin_dashboard.php");
    exit;
}

// --- Handle feedback submission ---
if (isset($_POST['send_feedback'])) {
    $complaint_id = $_POST['complaint_id'];
    $feedback_text = trim($_POST['feedback_text']);

    // Find user of this complaint
    $stmt = $conn->prepare("SELECT user_id FROM complaints WHERE id = ?");
    $stmt->bind_param("i", $complaint_id);
    $stmt->execute();
    $stmt->bind_result($user_id);
    $stmt->fetch();
    $stmt->close();

 if ($user_id && !empty($feedback_text)) {
    // Insert feedback
    $fstmt = $conn->prepare("INSERT INTO feedback (complaint_id, user_id, feedback_text, created_at) VALUES (?, ?, ?, NOW())");
    $fstmt->bind_param("iis", $complaint_id, $user_id, $feedback_text);
    $fstmt->execute();
    $fstmt->close();

    // Log activity
    $log = $conn->prepare("INSERT INTO activity_log (user_id, action, timestamp) VALUES (?, 'Sent Feedback', NOW())");
    $log->bind_param("i", $user_id);
    $log->execute();
    $log->close();

    // Gmail notification
    $userQuery = $conn->prepare("
    SELECT u.id, u.email_encrypted, u.username_encrypted
    FROM complaints c
    JOIN users u ON c.user_id = u.id
    WHERE c.id = ?
    ");
    $userQuery->bind_param("i", $complaint_id);
    $userQuery->execute();
    $user = $userQuery->get_result()->fetch_assoc();
    $userQuery->close();

    if ($user) {
        $email = decrypt_data($user['email_encrypted']);
        $message = "You have received feedback for complaint</p><strong> ID: $complaint_id</strong> <br>
        Feedback: $feedback_text ";

        // Send email
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';
        $mail->SMTPAuth = true;
        $mail->Username = '23-69926@g.batstate-u.edu.ph';     // Your Gmail
        $mail->Password = 'vcoi ufnq duxh pnyn';       // Your Gmail App Password
        $mail->SMTPSecure = 'ssl';
        $mail->Port = 465;

        // Email details
        $mail->setFrom('admin@gmail.com');
        $mail->addAddress($email); // user email

        $mail->isHTML(true);
        $mail->Subject = "New Feedback Received";
        $mail->Body = ($message);
        }
        $mail->send();
       
        $_SESSION['toastMessage'] = "Feedback sent successfully for Complaint #$complaint_id.";
    } else {
        $_SESSION['toastMessage'] = "Failed to send feedback.";
    }

    header("Location: admin_dashboard.php");
    exit;
}

// --- Map ---
$locQuery = $conn->query("
    SELECT c.user_id, c.id, c.latitude, c.longitude, c.created_at
    FROM complaints c
    LEFT JOIN users u ON c.user_id = u.id
    WHERE c.latitude IS NOT NULL 
      AND c.longitude IS NOT NULL
");

$locData = [];
while ($row = $locQuery->fetch_assoc()) {
    $locData[] = [
        "user_id"  => $row['user_id'],
        "complaint_id"  => $row['id'],
        "lat"      => $row['latitude'],
        "lng"      => $row['longitude'],
        "date"     => $row['created_at']
    ];
}

// --- Activity Log ---
$logs = $conn->query("SELECT * FROM activity_log ORDER BY timestamp DESC");

?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="css/admin.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div class="modal-overlay"></div>

    <?php
    if (isset($_SESSION['toastMessage'])) {
        $toastMessage = $_SESSION['toastMessage'];
        unset($_SESSION['toastMessage']); // Clear after showing
    } else {
        $toastMessage = '';
    }
    ?>
    <div id="toast"><?php echo htmlspecialchars($toastMessage); ?></div>

    <div class="sidebar">
        <h2 class="logo">MySystem</h2>
        <a href="#" class="nav-btn" data-section="dashboard">🏠 Dashboard</a>
        <a href="#" class="nav-btn" data-section="complaints">📄 Complaints</a>
        <a href="#" class="nav-btn" data-section="mapp">🌍 Map</a>
        <a href="#" class="nav-btn" data-section="activity_log">🕘 Activity Log</a>
        <a href="#" class="logout" onclick="openLogoutModal()">🚪 Logout</a>
    </div>

    <div class="main">

        <div class="topbar">
            <h2>Welcome, <?php echo htmlspecialchars($username); ?>!</h2>
        </div>
        
        <div class="content">

            <section id="dashboard" class="section">
                <h3>Dashboard Overview</h3>

                <div class="filter-container" style="margin-bottom: 15px;">
                    <label id="dateFilter-label"  for="dateFilter">Filter by Date: </label>
                    <select id="dateFilter" style="padding:5px; width:200px;">
                        <option value="all">All Time</option>
                        <option value="month">This Month</option>
                        <option value="week">This Week</option>
                        <option value="today">Today</option>
                    </select>
                </div>

                <div class="cards">
                    <div class="card"><h4>Total Complaints</h4><p><?php echo $stats['total']; ?></p></div>
                    <div class="card"><h4>Pending</h4><p><?php echo $stats['pending']; ?></p></div>
                    <div class="card"><h4>In Progress</h4><p><?php echo $stats['in_progress']; ?></p></div>
                    <div class="card"><h4>Resolved</h4><p><?php echo $stats['resolved']; ?></p></div>

                </div>
                <div class="chart">
                    <canvas id="complaintPieChart" style="text-align: center;"></canvas>
                </div>
            </section>

            <section id="complaints" class="section">
                <h3>All Submitted Complaints</h3>
                <div style="margin-bottom: 15px;">
                    <input type="text" id="searchFilter" placeholder="Search" style="padding:5px; width:300px;">
                    <select id="sortSelect" style="padding:5px; width:200px;">
                        <option value="">Sort By...</option>
                        <option value="id">ID</option>
                        <option value="pending">Pending</option>
                        <option value="progress">In Progress</option>
                        <option value="resolved">Resolved</option>
                        <option value="date">Date (newest submitted)</option>
                        <option value="from">From</option>
                    </select>
                </div>

                <table>
                    <tr>
                        <th>ID</th>
                        <th>Complaint</th>
                        <th>Status</th>
                        <th>Date Submitted</th>
                        <th>From</th>
                        <th>Actions</th>
                    </tr>

                    <?php
                    $query = "
                        SELECT c.*, u.username_encrypted
                        FROM complaints c
                        LEFT JOIN users u ON c.user_id = u.id
                        ORDER BY c.created_at DESC
                    ";
                    $result = $conn->query($query);

                    while ($row = $result->fetch_assoc()) {
                        $display_user = ($row['is_anonymous'] == 1 || $row['user_id'] === NULL)
                            ? "Anonymous"
                            : decrypt_data($row['username_encrypted']);
                        
                        $complaintText = htmlspecialchars($row['complaint_text'], ENT_QUOTES);

                        echo "<tr>
                            <td>{$row['id']}</td>
                            <td><button class='show-complaint-btn' onclick='showComplaintModal(\"{$complaintText}\")'>Show Complaint</button></td>
                            <td>{$row['status']}</td>
                            <td>{$row['created_at']}</td>
                            <td>{$display_user}</td>
                            <td>
                                <form method='POST' onsubmit=\"return confirm('Are you sure you want to update this complaint status?');\" style='display:inline-block;'>
                                    <input type='hidden' name='complaint_id' value='{$row['id']}'>
                                    <select id='status' name='status'>
                                        <option value='Pending' " . ($row['status'] == 'Pending' ? 'selected' : '') . ">Pending</option>
                                        <option value='In Progress' " . ($row['status'] == 'In Progress' ? 'selected' : '') . ">In Progress</option>
                                        <option value='Resolved' " . ($row['status'] == 'Resolved' ? 'selected' : '') . ">Resolved</option>
                                    </select>
                                    <button type='submit' name='update_status'>Update</button>
                                </form>
                                <button class='feedback-btn' onclick='openCreateFeedbackModal({$row['id']})'>Create Feedback</button>
                                <button class='view' onclick='viewFeedback({$row['id']})'>View Feedback</button>
                            </td>
                        </tr>";
                    }
                    ?>
                </table>
            </section>

            <section id="mapp" class="section">
                <!-- MAP MODAL -->
                <div id="mapModal" class="mapmodal">
                    <div class="mapmodal-content" style="width:100%; max-width:1000px;">
                        <h3>Complaint Location Map</h3>
                        <div id="map" style="height:550px; width:100%;"></div>
                    </div>
                </div>
            </section>

            <section id="activity_log" class="section">
                <?php
                $logQuery = "
                    SELECT al.*, u.username_encrypted
                    FROM activity_log al
                    LEFT JOIN users u ON al.user_id = u.id
                    ORDER BY al.timestamp DESC
                ";
                $logs = $conn->query($logQuery);
                ?>

                <table class="log-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>User</th>
                            <th>Action</th>
                            <th>Timestamp</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php
                        while ($log = $logs->fetch_assoc()) {

                            // Show username or "System"
                            $display_user = ($log['user_id'] === NULL)
                                ? "System"
                                : decrypt_data($log['username_encrypted']);

                            echo "
                            <tr>
                                <td>{$log['id']}</td>
                                <td>{$display_user}</td>
                                <td>{$log['action']}</td>
                                <td>{$log['timestamp']}</td>
                            </tr>";
                        }
                        ?>
                    </tbody>
                </table>
            </section>

        </div>
    </div>
    
    <!-- Complaint Modal -->
    <div id="complaintModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeComplaintModal()">&times;</span>
            <h3>Complaint</h3>
            <div id="complaintModalBody"></div>
        </div>
    </div>

    <!-- Feedback Modal -->
    <div id="createfeedbackModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeCreateFeedbackModal()">&times;</span>
            <h3>Create Feedback</h3>
            <form method="POST">
                <input type="hidden" id="complaint_id_input" name="complaint_id">
                <textarea name="feedback_text" rows="10" style="width:100%;" placeholder="Write your feedback here..." required></textarea><br><br>
                <button type="submit" name="send_feedback">Send Feedback</button>
            </form>
        </div>
    </div>

    <!-- Feedback Modal -->
    <div id="feedbackModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeFeedbackModal()">&times;</span>
            <div id="modalBody"></div>
        </div>
    </div>

    <!-- Single Feedback Modal -->
    <div id="singleFeedbackModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeSingleFeedbackModal()">&times;</span>
            <h3>Feedback Message</h3>
            <div id="singleFeedbackBody"></div>
        </div>
    </div>

    <!-- Logout Confirmation Modal -->
    <div id="logoutModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeLogoutModal()">&times;</span>

            <h3>Confirm Logout</h3>
            <p>Are you sure you want to log out?</p>

            <div style="margin-top: 20px; text-align: right;">
                <button class="cancel" onclick="closeLogoutModal()">Cancel</button>

                <form action="logout.php" method="POST" style="display:inline;">
                    <button type="submit" class="delete">Logout</button>
                </form>
            </div>
        </div>
    </div>

    <script>

        const toastMessage = "<?php echo addslashes($toastMessage); ?>";
        if (toastMessage) {
            const toast = document.getElementById("toast");
            toast.className = "show";
            setTimeout(() => { toast.className = toast.className.replace("show", ""); }, 3000);
        }

        // Sidebar buttons
        const navButtons = document.querySelectorAll(".nav-btn");
        const sections = document.querySelectorAll(".section");

        // Load active section on page load
        document.addEventListener("DOMContentLoaded", () => {
            let saved = localStorage.getItem("activeSection");

            // Default to dashboard on fresh login
            if (!saved) {
                saved = "dashboard"; // default section
                localStorage.setItem("activeSection", saved);
            }

            // Remove all active states
            navButtons.forEach(b => b.classList.remove("active"));
            sections.forEach(sec => sec.classList.remove("active"));

            // Apply the saved button + section
            const savedBtn = document.querySelector(`[data-section="${saved}"]`);
            const savedSec = document.getElementById(saved);

            if (savedBtn) savedBtn.classList.add("active");
            if (savedSec) savedSec.classList.add("active");
        });

        // Sidebar switching + save when clicked
        navButtons.forEach(btn => {
            btn.addEventListener("click", () => {

                // Save selected section
                localStorage.setItem("activeSection", btn.getAttribute("data-section"));

                // Remove active from all sidebar buttons
                navButtons.forEach(b => b.classList.remove("active"));
                btn.classList.add("active");

                // Hide all sections
                sections.forEach(sec => sec.classList.remove("active"));

                // Show selected section
                const target = btn.getAttribute("data-section");
                document.getElementById(target).classList.add("active");
            });
        });

        const ctx = document.getElementById('complaintPieChart').getContext('2d');

        const percentPlugin = {
            id: 'percentPlugin',
            afterDraw(chart) {
                const { ctx } = chart;
                const total = chart.options.plugins.percentPlugin.total || 1;

                chart.data.datasets[0].data.forEach((value, i) => {
                    if (value === 0) return;

                    const meta = chart.getDatasetMeta(0);
                    const pos = meta.data[i].tooltipPosition();
                    const percent = ((value / total) * 100).toFixed(1) + '%';

                    ctx.save();
                    ctx.fillStyle = "#fff";
                    ctx.font = "bold 17px Arial";
                    ctx.textAlign = "center";
                    ctx.fillText(percent, pos.x, pos.y);
                    ctx.restore();
                });
            }
        };

        let complaintPieChart = new Chart(ctx, {
            type: 'pie',
            data: {
                datasets: [{
                    data: [<?= $stats['pending']; ?>, <?= $stats['in_progress']; ?>, <?= $stats['resolved']; ?>],
                    backgroundColor: ['#f5a623', '#7ed321', '#4a90e2'],
                    borderColor: '#000',
                    borderWidth: 0.5
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        labels: { color: "#fff", font: { size: 14 } }
                    },
                    tooltip: { enabled: false },
                    percentPlugin: { total: <?= $stats['total']; ?> }
                }
            },
            plugins: [percentPlugin]
        });

        //   AJAX Date Filter (updated)
        document.getElementById('dateFilter').addEventListener('change', function() {
            const filter = this.value;

            const xhr = new XMLHttpRequest();
            xhr.open('POST', '', true);
            xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');

            xhr.onload = function() {
                if (xhr.status === 200) {
                    const stats = JSON.parse(xhr.responseText);

                    // Update stat cards
                    document.querySelector(".card:nth-child(1) p").textContent = stats.total;
                    document.querySelector(".card:nth-child(2) p").textContent = stats.pending;
                    document.querySelector(".card:nth-child(3) p").textContent = stats.in_progress;
                    document.querySelector(".card:nth-child(4) p").textContent = stats.resolved;

                    // Update Pie Chart
                    complaintPieChart.data.datasets[0].data = [
                        stats.pending,
                        stats.in_progress,
                        stats.resolved
                    ];

                    // Update total for percentage calculation
                    complaintPieChart.options.plugins.percentPlugin.total = stats.total;

                    complaintPieChart.update();
                }
            };

            xhr.send('filter=' + filter + '&ajax=1');
        });
        
        const searchFilter = document.getElementById('searchFilter');
        const tableRows = document.querySelector('table').getElementsByTagName('tr');

        function filterTable() {
            const filterText = searchFilter.value.toLowerCase();

            for (let i = 1; i < tableRows.length; i++) { // skip header
                const cells = tableRows[i].getElementsByTagName('td');

                const idCell = cells[0].textContent.toLowerCase();
                const statusCell = cells[2].textContent.toLowerCase();
                const dateCell = cells[3].textContent.toLowerCase();
                const fromCell = cells[4].textContent.toLowerCase();

                // Check if any cell matches the search text
                if (idCell.includes(filterText) || 
                    statusCell.includes(filterText) || 
                    dateCell.includes(filterText) || 
                    fromCell.includes(filterText)) {
                    tableRows[i].style.display = '';
                } else {
                    tableRows[i].style.display = 'none';
                }
            }
        }

        // Event listener
        searchFilter.addEventListener('keyup', filterTable);
        document.getElementById('sortSelect').addEventListener('change', sortTable);

        function sortTable() {
            let sortValue = document.getElementById("sortSelect").value;
            let table = document.querySelector("table");
            let rows = Array.from(table.rows).slice(1); // skip header row

            rows.sort((a, b) => {
                let a_id = parseInt(a.cells[0].textContent);
                let b_id = parseInt(b.cells[0].textContent);

                let a_date = new Date(a.cells[3].textContent);
                let b_date = new Date(b.cells[3].textContent);

                let a_from = a.cells[4].textContent.toLowerCase();
                let b_from = b.cells[4].textContent.toLowerCase();

                let a_status = a.cells[2].textContent;
                let b_status = b.cells[2].textContent;

                switch (sortValue) {

                    case "id":
                        return a_id - b_id;

                    case "date":
                        return b_date - a_date; // newest first

                    case "from":
                        return a_from.localeCompare(b_from);

                    case "pending":
                        return (a_status === "Pending" ? -1 : 1);

                    case "progress":
                        return (a_status === "In Progress" ? -1 : 1);

                    case "resolved":
                        return (a_status === "Resolved" ? -1 : 1);

                    default:
                        return 0;
                }
            });

            // Re-append rows in sorted order
            rows.forEach(row => table.appendChild(row));
        }

        function openLogoutModal() {
            document.getElementById("logoutModal").style.display = "block";
            document.body.classList.add('modal-open');
            document.querySelector('.modal-overlay').style.display = 'block';
        }

        function closeLogoutModal() {
            document.getElementById("logoutModal").style.display = "none";
            document.querySelector('.modal-overlay').style.display = 'none';
            document.body.classList.remove('modal-open');
        }
        
        // Observe section switching
        document.querySelectorAll(".nav-btn").forEach(btn => {
            btn.addEventListener("click", function() {
                const target = this.getAttribute("data-section");

                if (target === "mapp") {
                    // Give time for the section to fade-in then load map
                    setTimeout(() => {
                        openMapModal();
                    }, 200);
                }
            });
        });
        
        var allLocations = <?php echo json_encode($locData); ?>;

        let mapLoaded = false;
        let leafletMap;

        function openMapModal() {
            const modal = document.getElementById("mapModal");
            modal.style.display = "flex";
            

            if (!mapLoaded) {
                setTimeout(loadMap, 200);
                mapLoaded = true;
            }
        }

        function loadMap() {
            leafletMap = L.map('map').setView([12.8797, 121.7740], 6);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap'
            }).addTo(leafletMap);

            allLocations.forEach(loc => {
                L.marker([loc.lat, loc.lng])
                    .addTo(leafletMap)
                    .bindPopup(
                        "User ID: " + loc.user_id + "<br>" +
                        "Complaint ID: " + loc.complaint_id + "<br>" +
                        "Lat: " + loc.lat + "<br>" +
                        "Lng: " + loc.lng + "<br>" +
                        "Saved: " + loc.date
                    );
            });
        }

        // ✅ PART 1: When user clicks Map in sidebar → Load Map
        document.querySelectorAll(".nav-btn").forEach(btn => {
            btn.addEventListener("click", () => {
                const sectionName = btn.dataset.section;

                if (sectionName === "mapp") {
                    openMapModal();
                 
                }
            });
        });

        // ✅ PART 2: If Map section is active on page refresh → Auto-load
        window.addEventListener("load", () => {
            const mapSection = document.getElementById("mapp");

            // If mapp section already has "active", auto-load map
            if (mapSection.classList.contains("active")) {
                openMapModal();
            }
        });

        // Complaint modal functions
        function showComplaintModal(text) {
            const modal = document.getElementById('complaintModal');
            const body = document.getElementById('complaintModalBody');
            body.textContent = text;
            modal.style.display = 'flex';
            document.querySelector('.modal-overlay').style.display = 'block';
            document.body.classList.add('modal-open');
        }

        function closeComplaintModal() {
            document.getElementById('complaintModal').style.display = 'none';
            document.querySelector('.modal-overlay').style.display = 'none';
            document.body.classList.remove('modal-open');
        }

        // Feedback modal functions
        const createfeedbackModal = document.getElementById('createfeedbackModal');
        const complaintInput = document.getElementById('complaint_id_input');

        function openCreateFeedbackModal(id) {
            complaintInput.value = id;
            createfeedbackModal.style.display = 'flex';
            document.querySelector('.modal-overlay').style.display = 'block';
            document.body.classList.add('modal-open');
        }

        function closeCreateFeedbackModal() {
            document.getElementById('createfeedbackModal').style.display = 'none';
            document.querySelector('.modal-overlay').style.display = 'none';
            document.body.classList.remove('modal-open');
        }

        function viewFeedback(complaintId) {
            const modal = document.getElementById("feedbackModal");
            const modalBody = document.getElementById("modalBody");
            modal.style.display = "block";
            modalBody.innerHTML = "<p>Loading feedback...</p>";
            document.querySelector('.modal-overlay').style.display = 'block';
            document.body.classList.add('modal-open');

            const xhr = new XMLHttpRequest();
            xhr.open("GET", "view_feedback.php?complaint_id=" + complaintId, true);
            xhr.onload = function() {
                modalBody.innerHTML = xhr.status === 200 ? xhr.responseText : "<p>Error loading feedback.</p>";
            };
            xhr.send();
        }

        function closeFeedbackModal() {
            feedbackModal.style.display = 'none';
            document.querySelector('.modal-overlay').style.display = 'none';
            document.body.classList.remove('modal-open');
        }

        function showSingleFeedback(text) {
            const modal = document.getElementById('singleFeedbackModal');
            const body = document.getElementById('singleFeedbackBody');
            body.textContent = text;
            modal.style.display = 'block';
        }

        function closeSingleFeedbackModal() {
            document.getElementById('singleFeedbackModal').style.display = 'none';
            document.querySelector('.modal-overlay').style.display = 'none';
            document.body.classList.remove('modal-open');
        }
        
    </script>
    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>

</body>
</html>