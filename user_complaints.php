<?php
include('db.php');
session_start();

// Redirect if not logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$toastMessage = '';

// --- Clear last complaint flag after redirect ---
if (isset($_SESSION['clear_last_complaint'])) {
    unset($_SESSION['last_complaint']);
    unset($_SESSION['clear_last_complaint']);
}

// --- Handle AJAX filter for dashboard ---
if (isset($_POST['ajax']) && $_POST['ajax'] == 1) {
    $filter = $_POST['filter'] ?? 'all';
    $where = "WHERE user_id = ?";

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
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_assoc();

    echo json_encode($data);
    exit;
}

// --- Default dashboard stats (All Time) ---
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
    WHERE user_id = ?
");

$stmt->bind_param("i", $user_id);
$stmt->execute();
$result = $stmt->get_result();

if ($result) {
    $row = $result->fetch_assoc();
    if ($row) $stats = $row;
}

// --- Handle complaint deletion ---
if (isset($_POST['delete_complaint'])) {
    $complaint_id = $_POST['complaint_id'];

    $stmt1 = $conn->prepare("DELETE FROM feedback WHERE complaint_id = ?");
    $stmt1->bind_param("i", $complaint_id);
    $stmt1->execute();
    $stmt1->close();

    $stmt2 = $conn->prepare("DELETE FROM complaints WHERE id = ? AND user_id = ?");
    $stmt2->bind_param("ii", $complaint_id, $user_id);
    $stmt2->execute();
    $stmt2->close();

    $stmt = $conn->prepare("
        INSERT INTO activity_log (user_id, action, timestamp) 
        VALUES (?, 'Complaint deletion (Complaint ID: $complaint_id)', NOW())
    ");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $stmt->close();

    $_SESSION['toastMessage'] = 'Complaint deleted successfully!';
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// --- Handle new complaint submission ---
if (isset($_POST['complaint'])) {
    if (isset($_SESSION['last_complaint']) && $_SESSION['last_complaint'] === $_POST['message']) {
        $_SESSION['toastMessage'] = 'This complaint was already submitted.';
    } else {
        $message = trim($_POST['message']);
        $is_anonymous = isset($_POST['anonymous']) ? 1 : 0;

        if (strlen($message) > 10000) {
            $_SESSION['toastMessage'] = 'Complaint too long. Please limit to 10000 characters.';
        } elseif (empty($message)) {
            $_SESSION['toastMessage'] = 'Please enter a complaint.';
        } else {
            $lat = !empty($_POST['latitude']) ? $_POST['latitude'] : NULL;
            $lng = !empty($_POST['longitude']) ? $_POST['longitude'] : NULL;

            $stmt = $conn->prepare("INSERT INTO complaints (user_id, complaint_text, is_anonymous, latitude, longitude)
                                    VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("isidd", $user_id, $message, $is_anonymous, $lat, $lng);
            $stmt->execute();

            $complaint_id = $stmt->insert_id;
            $stmt->close();

            $action_text = "Complaint created (Complaint ID: $complaint_id)";
            $stmt = $conn->prepare("INSERT INTO activity_log (user_id, action, timestamp) VALUES (?, ?, NOW())");
            $stmt->bind_param("is", $user_id, $action_text);
            $stmt->execute();
            $stmt->close();

            $_SESSION['toastMessage'] = 'Complaint submitted successfully!';
            $_SESSION['last_complaint'] = $message;
            $_SESSION['clear_last_complaint'] = true;
        }
    }
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>User Dashboard</title>
    <link rel="stylesheet" href="css/user_complaints.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div class="modal-overlay"></div>

    <?php
    if (isset($_SESSION['toastMessage'])) {
        $toastMessage = $_SESSION['toastMessage'];
        unset($_SESSION['toastMessage']); 
    } else {
        $toastMessage = '';
    }
    ?>
    <div id="toast"><?php echo htmlspecialchars($toastMessage); ?></div>

    <div class="sidebar">
        <h2 class="logo">MySystem</h2>
        <a href="#" class="nav-btn" data-section="dashboard">🏠 Dashboard</a>
        <a href="#" class="nav-btn" data-section="submit">📝 Submit Complaint</a>
        <a href="#" class="nav-btn" data-section="mycomplaints">📄 My Complaints</a>
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

            <section id="submit" class="section">

                <h3>Submit a Complaint</h3>
                <form action="" method="POST">

                    <textarea style="height: 293px; width:1075px;" id="message" name="message" maxlength="10000" required oninput="updateCharCount()"></textarea>
                    <div id="charCount">0/10000</div><br>

                    <label><input type="checkbox" name="anonymous" value="1"> Submit anonymously</label><br><br>

                    <!-- New checkbox -->
                    <label><input type="checkbox" id="enableLocation"> Add Location</label><br><br>

                    <!-- Hidden inputs to store map coordinates -->
                    <input type="hidden" id="latField" name="latitude">
                    <input type="hidden" id="lngField" name="longitude">

                    <input type="submit" name="complaint" value="Submit Complaint">
                </form>

            </section>

            <section id="mycomplaints" class="section">

                <h3>My Complaints</h3>

                <div style="margin-bottom: 15px;">
                    <input type="text" id="searchFilter" placeholder="Search" style="padding:5px; width:300px;">
                    <select id="sortSelect" style="padding:5px; width:200px;">
                        <option value="">Sort By...</option>
                        <option value="id">ID</option>
                        <option value="pending">Pending</option>
                        <option value="progress">In Progress</option>
                        <option value="resolved">Resolved</option>
                        <option value="date">Date (newest submitted)</option>
                        <option value="from">Submitted As</option>
                    </select>
                </div>

                <table>
                    <tr>
                        <th>ID</th>
                        <th>Complaint</th>
                        <th>Status</th>
                        <th>Date Submitted</th>
                        <th>Submitted As</th>
                        <th>Action</th>
                    </tr>
                    <?php
                    $result = $conn->query("SELECT * FROM complaints WHERE user_id = $user_id ORDER BY created_at DESC");
                    while ($row = $result->fetch_assoc()) {
                        $complaintText = htmlspecialchars($row['complaint_text'], ENT_QUOTES);
                        $submittedAs = $row['is_anonymous'] ? 'Anonymous' : htmlspecialchars($username);

                        echo "<tr>
                                <td>{$row['id']}</td>
                                <td><button onclick='showComplaintModal(\"{$complaintText}\")'>Show Complaint</button></td>
                                <td>{$row['status']}</td>
                                <td>{$row['created_at']}</td>
                                <td>{$submittedAs}</td>
                                <td>
                                    <form method='POST' style='display:inline;'>
                                        <input type='hidden' name='complaint_id' value='{$row['id']}'>
                                        <button class='delete' type='submit' name='delete_complaint' onclick='return confirm(\"Delete this complaint?\");'>Delete</button>
                                    </form>
                                    <button class='view' onclick='viewFeedback({$row['id']})'>View Feedback</button>
                                </td>
                            </tr>";
                    }
                    ?>
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
    <div id="feedbackModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeModal()">&times;</span>
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

    <!-- Map Modal -->
    <div id="mapModal" class="modal">
        <div class="mapmodal-content" style="width: 80%; height: 80%;">
            <span class="close" onclick="closeMap()">&times;</span>
            <h3>Select Location</h3>
            <div id="map" style="width: 100%; height: 90%;"></div>
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
                    legend: { labels: { color: "#fff", font: { size: 14 } } },
                    tooltip: { enabled: false },
                    percentPlugin: { total: <?= $stats['total']; ?> }
                }
            },
            plugins: [percentPlugin]
        });

        // AJAX filter for date selection
        document.getElementById('dateFilter').addEventListener('change', function() {
            const filter = this.value;
            const xhr = new XMLHttpRequest();
            xhr.open('POST', '', true);
            xhr.setRequestHeader('Content-type', 'application/x-www-form-urlencoded');
            xhr.onload = function() {
                if (xhr.status === 200) {
                    const stats = JSON.parse(xhr.responseText);
                    document.querySelector(".card:nth-child(1) p").textContent = stats.total;
                    document.querySelector(".card:nth-child(2) p").textContent = stats.pending;
                    document.querySelector(".card:nth-child(3) p").textContent = stats.in_progress;
                    document.querySelector(".card:nth-child(4) p").textContent = stats.resolved;

                    complaintPieChart.data.datasets[0].data = [stats.pending, stats.in_progress, stats.resolved];
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

        function viewFeedback(complaintId) {
            const modal = document.getElementById("feedbackModal");
            const modalBody = document.getElementById("modalBody");
            modal.style.display = "block";
            modalBody.innerHTML = "<p>Loading feedback...</p>";
            document.body.classList.add('modal-open');
            document.querySelector('.modal-overlay').style.display = 'block';

            const xhr = new XMLHttpRequest();
            xhr.open("GET", "view_feedback.php?complaint_id=" + complaintId, true);
            xhr.onload = function() {
                modalBody.innerHTML = xhr.status === 200 ? xhr.responseText : "<p>Error loading feedback.</p>";
            };
            xhr.send();
        }

        function showSingleFeedback(text) {
            const modal = document.getElementById('singleFeedbackModal');
            const body = document.getElementById('singleFeedbackBody');
            body.textContent = text;
            modal.style.display = 'block';
            document.body.classList.add('modal-open');
            document.querySelector('.modal-overlay').style.display = 'block';
        }

        function closeSingleFeedbackModal() {
            document.getElementById('singleFeedbackModal').style.display = 'none';
            document.body.classList.remove('modal-open');
            document.querySelector('.modal-overlay').style.display = 'none';
        }

        function closeModal() {
            document.getElementById("feedbackModal").style.display = "none";
            document.body.classList.remove('modal-open');
            document.querySelector('.modal-overlay').style.display = 'none';
        }

        function showComplaintModal(text) {
            const modal = document.getElementById('complaintModal');
            const body = document.getElementById('complaintModalBody');
            body.textContent = text;
            modal.style.display = 'block';
            document.body.classList.add('modal-open');
            document.querySelector('.modal-overlay').style.display = 'block';
        }

        function closeComplaintModal() {
            document.getElementById('complaintModal').style.display = 'none';
            document.querySelector('.modal-overlay').style.display = 'none';
            document.body.classList.remove('modal-open');
            document.querySelector('.modal-overlay').style.display = 'none';
        }

        function updateCharCount() {
            const textarea = document.getElementById('message');
            const counter = document.getElementById('charCount');
            counter.textContent = textarea.value.length + "/10000";
        }

        // --- MAP VARIABLES ---
        let map, marker;

        // Show map modal when checkbox is checked
        document.addEventListener("DOMContentLoaded", () => {
            document.getElementById("enableLocation").addEventListener("change", function() {
                if (this.checked) {
                    openMap();
                } else {
                    closeMap();
                    document.getElementById("latField").value = "";
                    document.getElementById("lngField").value = "";
                }
            });
        });

        function openMap() {
            const modal = document.getElementById("mapModal");
            modal.style.display = "block";

            setTimeout(() => {
                if (!map) {
                    // Initialize map
                    map = L.map('map').setView([13.7565, 121.0583], 10);

                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: 'Map data © OpenStreetMap contributors'
                    }).addTo(map);

                    // Click event to drop pin
                    map.on("click", function(e) {
                        const lat = e.latlng.lat;
                        const lng = e.latlng.lng;

                        if (marker) marker.remove();

                        marker = L.marker([lat, lng]).addTo(map)
                            .bindPopup("Selected Location").openPopup();

                        // Save to hidden fields
                        document.getElementById("latField").value = lat;
                        document.getElementById("lngField").value = lng;

                        alert("Location Selected!");
                    });
                } else {
                    map.invalidateSize();
                }
            }, 300);
        }

        function closeMap() {
            const lat = document.getElementById("latField").value;
            const lng = document.getElementById("lngField").value;

            if (lat === "" || lng === "") {            
                document.getElementById("enableLocation").checked = false;
            }   

            document.getElementById("mapModal").style.display = "none";
        }

        function openLogoutModal() {
            document.getElementById("logoutModal").style.display = "block";
            document.body.classList.add('modal-open');
            document.querySelector('.modal-overlay').style.display = 'block';
        }

        function closeLogoutModal() {
            document.getElementById("logoutModal").style.display = "none";
            document.body.classList.remove('modal-open');
            document.querySelector('.modal-overlay').style.display = 'none';
        }

        const toastMessage = "<?php echo addslashes($toastMessage); ?>";
        if (toastMessage) {
            const toast = document.getElementById("toast");
            toast.className = "show";
            setTimeout(() => { toast.className = toast.className.replace("show", ""); }, 3000);
        }

    </script>
    
    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>

</body>
</html>
