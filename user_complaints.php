<?php
include('db.php');
session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$toastMessage = '';

// --- Handle complaint deletion ---
if (isset($_POST['delete_complaint'])) {
    $complaint_id = $_POST['complaint_id'];

    // Delete related feedback first
    $stmt1 = $conn->prepare("DELETE FROM feedback WHERE complaint_id = ?");
    $stmt1->bind_param("i", $complaint_id);
    $stmt1->execute();
    $stmt1->close();

    // Then delete the complaint
    $stmt2 = $conn->prepare("DELETE FROM complaints WHERE id = ? AND user_id = ?");
    $stmt2->bind_param("ii", $complaint_id, $user_id);
    $stmt2->execute();
    $stmt2->close();
    $toastMessage = 'Complaint deleted successfully!';

}

// --- Submit new complaint ---
if (isset($_POST['complaint'])) {
    $message = trim($_POST['message']);
    $is_anonymous = isset($_POST['anonymous']) ? 1 : 0;

    if (strlen($message) > 10000) {
        echo "<script>alert('Complaint too long. Please limit to 10000 characters.');</script>";
    } elseif (empty($message)) {
        echo "<script>alert('Please enter a complaint.');</script>";
    } else {
        // Save complaint to database
        
        $lat = !empty($_POST['latitude']) ? $_POST['latitude'] : NULL;
        $lng = !empty($_POST['longitude']) ? $_POST['longitude'] : NULL;

        $stmt = $conn->prepare("INSERT INTO complaints (user_id, complaint_text, is_anonymous, latitude, longitude) 
                                VALUES (?, ?, ?, ?, ?)");

        $stmt->bind_param("isidd", $user_id, $message, $is_anonymous, $lat, $lng);


        $stmt->execute();

        // ✅ NO notification is created for the user here
        // So the badge count will not change

        $toastMessage = 'Complaint submitted successfully!';
    }
}

// --- Handle notification actions ---
if (isset($_POST['notif_action']) && !empty($_POST['notif_ids'])) {
    $ids = $_POST['notif_ids'];
    $action = $_POST['notif_action'];
    $idList = implode(",", array_map('intval', $ids));

    if ($action == "read") {
        $conn->query("UPDATE notifications SET is_read = 1 WHERE id IN ($idList) AND user_id = $user_id");
    } elseif ($action == "unread") {
        $conn->query("UPDATE notifications SET is_read = 0 WHERE id IN ($idList) AND user_id = $user_id");
    } elseif ($action == "delete") {
        $conn->query("DELETE FROM notifications WHERE id IN ($idList) AND user_id = $user_id");
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>User Dashboard</title>
    <link rel="stylesheet" href="css/user_complaints.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet/dist/leaflet.css">
    <script src="https://unpkg.com/leaflet/dist/leaflet.js"></script>
    <script>
        // Close modal when clicking outside
        window.onclick = function(event) {
            const modals = ['singleFeedbackModal', 'feedbackModal', 'complaintModal', 'notifModal'];
            modals.forEach(id => {
                const modal = document.getElementById(id);
                if (event.target === modal) modal.style.display = 'none';
            });
        };

        function toggleNotifications() {
            const modal = document.getElementById('notifModal');
            modal.style.display = 'block';
        }

        function closeNotifModal() {
            document.getElementById('notifModal').style.display = 'none';
        }

        function toggleSelectAll(source) {
            const checkboxes = document.querySelectorAll('.notif-checkbox');
            checkboxes.forEach(cb => cb.checked = source.checked);
        }

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

        function closeModal() {
            document.getElementById("feedbackModal").style.display = "none";
        }

        function showComplaintModal(text) {
            const modal = document.getElementById('complaintModal');
            const body = document.getElementById('complaintModalBody');
            body.textContent = text;
            modal.style.display = 'block';
        }

        function closeComplaintModal() {
            document.getElementById('complaintModal').style.display = 'none';
        }

        function updateCharCount() {
            const textarea = document.getElementById('message');
            const counter = document.getElementById('charCount');
            counter.textContent = textarea.value.length + "/10000";
        }

        function showToast(message) {
            // Create toast
            const toast = document.createElement('div');
            toast.className = 'toast';
            toast.innerHTML = `
                <div>${message}</div>
                <div class="progress"><div class="progress-bar"></div></div>
            `;
            document.body.appendChild(toast);

            // Show animation
            setTimeout(() => toast.classList.add('show'), 100);

            // Hide after 3s
            setTimeout(() => {
                toast.classList.remove('show');
                setTimeout(() => toast.remove(), 400);
            }, 3000);
        }

        document.addEventListener('DOMContentLoaded', () => {
            <?php if(!empty($toastMessage)) { ?>
                showToast("<?php echo addslashes($toastMessage); ?>");
            <?php } ?>
        });

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
    document.getElementById("mapModal").style.display = "none";
}


    </script>
</head>
<body>
    <h2>Welcome, <?php echo htmlspecialchars($username); ?>!</h2>
    <a href="login.php">Logout</a>

    <?php
    // Fetch unread notifications count (this stays consistent)
    $countResult = $conn->query("SELECT COUNT(*) AS unread_count FROM notifications WHERE user_id = $user_id AND is_read = 0");
    $unread = $countResult->fetch_assoc()['unread_count'] ?? 0;
    ?>

    <button class="notif-btn" onclick="toggleNotifications()">
        🔔 Notifications
        <?php if ($unread > 0): ?>
            <span class="notif-count" id="notifCount"><?php echo $unread; ?></span>
        <?php endif; ?>
    </button>

    <h3>Submit a Complaint</h3>
    <form action="" method="POST">

        <label for="message">Complaint:</label><br>
        <textarea id="message" name="message" rows="10" cols="60" maxlength="10000" required oninput="updateCharCount()"></textarea>
        <div id="charCount">0/10000</div><br>

        <label><input type="checkbox" name="anonymous" value="1"> Submit anonymously</label><br><br>

        <!-- New checkbox -->
        <label><input type="checkbox" id="enableLocation"> Add Location</label><br><br>

        <!-- Hidden inputs to store map coordinates -->
        <input type="hidden" id="latField" name="latitude">
        <input type="hidden" id="lngField" name="longitude">

        <input type="submit" name="complaint" value="Submit Complaint">
    </form>


    <h3>Your Complaints</h3>
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
                    <td><button class='show-complaint-btn' onclick='showComplaintModal(\"{$complaintText}\")'>Show Complaint</button></td>
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

    <!-- Notifications Modal -->
    <div id="notifModal" class="modal">
        <div class="notifmodal-content">
            <span class="close" onclick="closeNotifModal()">&times;</span>
            <h3>Your Notifications</h3>

            <form method="POST" id="notifForm">
                <?php
                $notifResult = $conn->query("SELECT * FROM notifications WHERE user_id = $user_id ORDER BY created_at DESC");
                if ($notifResult->num_rows > 0) {
                    echo '<div style="margin-bottom:10px;">
                            <button type="submit" name="notif_action" value="read" class="action-btn read-btn">Mark as Read</button>
                            <button type="submit" name="notif_action" value="unread" class="action-btn unread-btn">Mark as Unread</button>
                            <button type="submit" name="notif_action" value="delete" class="action-btn delete-btn" onclick="return confirm(\'Delete selected notifications?\');">Delete</button>
                        </div>';
                    echo '<table>
                            <tr>
                                <th><input type="checkbox" onclick="toggleSelectAll(this)"> Select All</th>
                                <th>Message</th>
                                <th>Date</th>
                            </tr>';
                    while ($notif = $notifResult->fetch_assoc()) {
                        $isNew = $notif['is_read'] == 0 ? '<span class="new-label">(New)</span> ' : '';
                        echo "<tr>
                                <td><input type='checkbox' class='notif-checkbox' name='notif_ids[]' value='{$notif['id']}'></td>
                                <td>$isNew {$notif['message']}</td>
                                <td>{$notif['created_at']}</td>
                            </tr>";
                    }
                    echo '</table>';
                } else {
                    echo "<p>No notifications.</p>";
                }
                ?>
            </form>
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
                
    <!-- Map Modal -->
    <div id="mapModal" class="modal">
    <div class="modal-content" style="width: 80%; height: 80%;">
        <span class="close" onclick="closeMap()">&times;</span>
        <h3>Select Location</h3>
        <div id="map" style="width: 100%; height: 90%;"></div>
    </div>
</div>
</body>
</html>
