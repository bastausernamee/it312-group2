<?php
include('db.php');
session_start();

// --- Admin authentication check ---
if (!isset($_SESSION['user_id']) || $_SESSION['username'] !== 'admin') {
    header("Location: login.php");
    exit;
}

// --- Decrypt function ---
function decrypt_data($encrypted) {
    $key = "mysecretkey12345"; // Must match encryption key
    $iv = str_repeat("\0", 16);
    return openssl_decrypt($encrypted, "AES-128-CBC", $key, 0, $iv);
}

// --- Handle complaint status update ---
if (isset($_POST['update_status'])) {
    $complaint_id = $_POST['complaint_id'];
    $new_status = $_POST['status'];

    $stmt = $conn->prepare("UPDATE complaints SET status = ? WHERE id = ?");
    $stmt->bind_param("si", $new_status, $complaint_id);
    $stmt->execute();
    $stmt->close();

    // Get user info
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
        $message = "Hello $username, your complaint [<strong>ID: $complaint_id</strong>] has been updated to <strong>$new_status</strong>.";

        // Send email
        $subject = "Complaint Status Updated";
        $headers  = "From: admin@yourdomain.com\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        mail($email, $subject, $message, $headers);

        // Insert notification
        $notifStmt = $conn->prepare("INSERT INTO notifications (user_id, complaint_id, message) VALUES (?, ?, ?)");
        $notifStmt->bind_param("iis", $user['id'], $complaint_id, $message);
        $notifStmt->execute();
        $notifStmt->close();
    }

    $_SESSION['msg'] = "Complaint ID #$complaint_id has been updated to '$new_status'.";
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

        // Insert into notifications table
        $notifMsg = "Admin has sent you feedback for your complaint [<strong>ID: $complaint_id</strong>].";
        $notifStmt = $conn->prepare("INSERT INTO notifications (user_id, complaint_id, message) VALUES (?, ?, ?)");
        $notifStmt->bind_param("iis", $user_id, $complaint_id, $notifMsg);
        $notifStmt->execute();
        $notifStmt->close();

        $_SESSION['msg'] = "Feedback sent successfully for Complaint #$complaint_id.";
    } else {
        $_SESSION['msg'] = "Failed to send feedback.";
    }

    header("Location: admin_dashboard.php");
    exit;
}
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


?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="css/admin.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
    
    
</head>
<body>
    <h2>Welcome, Admin!</h2>
    <button class="logout" onclick="openLogoutModal()">Logout</button>

    <button class="map-btn" onclick="openMapModal()">View All Users on Map</button>

    <?php
    if (isset($_SESSION['msg'])) {
        echo "<div class='message'>{$_SESSION['msg']}</div>";
        unset($_SESSION['msg']);
    }
    ?>

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
                        <select name='status'>
                            <option value='Pending' " . ($row['status'] == 'Pending' ? 'selected' : '') . ">Pending</option>
                            <option value='In Progress' " . ($row['status'] == 'In Progress' ? 'selected' : '') . ">In Progress</option>
                            <option value='Resolved' " . ($row['status'] == 'Resolved' ? 'selected' : '') . ">Resolved</option>
                        </select>
                        <button type='submit' name='update_status'>Update</button>
                    </form>
                    <button class='feedback-btn' onclick='openFeedbackModal({$row['id']})'>Create Feedback</button>
                    <button class='view' onclick='viewFeedback({$row['id']})'>View Feedback</button>
                </td>
            </tr>";
        }
        ?>
    </table>

    <!-- ✅ Complaint Modal -->
    <div id="complaintModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeComplaintModal()">&times;</span>
            <h3>Complaint Details</h3>
            <div id="complaintModalBody"></div>
        </div>
    </div>

    <!-- Feedback Modal -->
    <div id="createfeedbackModal" class="modal">
        <div class="modal-content">
            <span class="close-btn" onclick="closeFeedbackModal()">&times;</span>
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
            <span class="close" onclick="closeModal()">&times;</span>
            <h3>Feedback</h3>
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

    <!-- MAP MODAL -->
    <div id="mapModal" class="modal">
        <div class="modal-content" style="width:90%; max-width:1000px;">
            <span class="close" onclick="closeMapModal()">&times;</span>
            <h3>User Locations Map</h3>
            <div id="map" style="height:600px; width:100%;"></div>
        </div>
    </div>

    <!-- Logout Confirmation Modal -->
    <div id="logoutModal" class="modal">
        <div class="modal-content">
            <span class="close" onclick="closeLogoutModal()">&times;</span>

            <h3>Confirm Logout</h3>
            <p>Are you sure you want to log out?</p>

            <div style="margin-top: 20px; text-align: right;">
                <button style="background:#6c757d;" onclick="closeLogoutModal()">Cancel</button>

                <form action="logout.php" method="POST" style="display:inline;">
                    <input type="submit" value="Logout" class="delete">
                </form>
            </div>
        </div>
    </div>

    <script>

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
    }

    function closeLogoutModal() {
        document.getElementById("logoutModal").style.display = "none";
    }

    // Close when clicking outside modal
    window.onclick = function(event) {
        let modal = document.getElementById("logoutModal");
        if (event.target === modal) {
            modal.style.display = "none";
        }
    }

        var allLocations = <?php echo json_encode($locData); ?>;

        let mapLoaded = false;
        let leafletMap;

        // Open modal and load map only once
        function openMapModal() {
            const modal = document.getElementById("mapModal");
            modal.style.display = "flex";

            if (!mapLoaded) {
                setTimeout(() => { loadMap(); }, 200); // delay for modal animation
                mapLoaded = true;
            }
        }

        function closeMapModal() {
            document.getElementById("mapModal").style.display = "none";
        }

        // Load map and markers
        function loadMap() {
            leafletMap = L.map('map').setView([12.8797, 121.7740], 6);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap'
            }).addTo(leafletMap);

            allLocations.forEach(loc => {
                L.marker([loc.lat, loc.lng]).addTo(leafletMap)
                .bindPopup(
                    "User_id: " + loc.user_id + "<br>" +
                    "Complaint_id: " + loc.complaint_id + "<br>" +
                    "Lat: " + loc.lat + "<br>" +
                    "Lng: " + loc.lng + "<br>" +
                    "Saved: " + loc.date
                );
            });
        }

        // Close modal when clicking outside
        window.onclick = function(event) {
            let modal = document.getElementById("mapModal");
            if (event.target == modal) {
                closeMapModal();
            }
        };

        // Complaint modal functions
        function showComplaintModal(text) {
            const modal = document.getElementById('complaintModal');
            const body = document.getElementById('complaintModalBody');
            body.textContent = text;
            modal.style.display = 'flex';
        }

        function closeComplaintModal() {
            document.getElementById('complaintModal').style.display = 'none';
        }

        // Feedback modal functions
        const createfeedbackModal = document.getElementById('createfeedbackModal');
        const complaintInput = document.getElementById('complaint_id_input');

        function openFeedbackModal(id) {
            complaintInput.value = id;
            createfeedbackModal.style.display = 'flex';
        }

        function closeFeedbackModal() {
            feedbackModal.style.display = 'none';
        }

        window.onclick = (e) => {
            if (e.target == feedbackModal) closeFeedbackModal();
            if (e.target == document.getElementById('complaintModal')) closeComplaintModal();
        };

        function viewFeedback(complaintId) {
            const modal = document.getElementById("feedbackModal");
            const modalBody = document.getElementById("modalBody");
            modal.style.display = "block";
            modalBody.innerHTML = "<p>Loading feedback...</p>";

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
        }

        function closeSingleFeedbackModal() {
            document.getElementById('singleFeedbackModal').style.display = 'none';
        }

        function closeFeedbackModal() {
            document.getElementById('createfeedbackModal').style.display = 'none';
        }

        function closeModal() {
            document.getElementById('feedbackModal').style.display = 'none';
        }

    </script>
</body>
</html>
