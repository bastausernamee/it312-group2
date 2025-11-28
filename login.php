<?php
include('db.php');
session_start();

function encrypt_data($data) {
    $key = 'mysecretkey12345';
    return openssl_encrypt($data, 'AES-128-ECB', $key);
}
function decrypt_data($data) {
    $key = 'mysecretkey12345';
    return openssl_decrypt($data, 'AES-128-ECB', $key);
}

if (isset($_POST['login'])) {
    $username = $_POST['username'];
    $password = $_POST['password'];

    $result = $conn->query("SELECT * FROM users");
    $logged_in = false;

    while ($row = $result->fetch_assoc()) {
        $decrypted_username = decrypt_data($row['username_encrypted']);
        $decrypted_email = decrypt_data($row['email_encrypted']);

        if ($decrypted_username === $username && password_verify($password, $row['password_hashed'])) {
            $_SESSION['user_id'] = $row['id'];
            $_SESSION['username'] = $decrypted_username;
            $_SESSION['email'] = $decrypted_email;

            $stmt = $conn->prepare("INSERT INTO activity_log (user_id, action, timestamp) VALUES (?, 'logged in', NOW())");
            $stmt->bind_param("i", $_SESSION['user_id']);
            $stmt->execute();
            $stmt->close();


            // Redirect admin to admin dashboard
            if ($decrypted_username === 'admin') {
                header("Location: admin_dashboard.php");
            } else {
                header("Location: user_complaints.php");
            }
            exit;
        }
    }

    echo "❌ Incorrect username or password!";
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Login</title>
    <link rel="stylesheet" href="css/form.css">
</head>

<body>
<h2 style="text-align:center;">Login Form</h2>

<?php
$xml = simplexml_load_file("structure.xml");
$styles = [];
foreach ($xml->styles->style as $st) {
    $styles[(string)$st['name']] = (string)$st;
}
?>

<div class="<?php echo $styles['form-wrapper']; ?>">
    <form method="POST">

        <?php
        foreach ($xml->form as $form) {
            if ($form['name'] == 'login') {
                foreach ($form->field as $field) {
                    $label_text = $field->label;
                    $css_label = $styles['label'];
                    $css_input = $styles[(string)$field->css];

                    echo "<label class='{$css_label}'>{$label_text}</label><br>";
                    echo "<input 
                            type='{$field->type}' 
                            name='{$field->name}' 
                            class='{$css_input}' 
                            required
                          ><br>";
                }

                // Button styling
                $button_css = $styles[(string)$form->{"button-class"}];
            }
        }
        ?>

        <input type="submit" name="login" value="Login" class="<?php echo $button_css; ?>">
    </form>
</div>

<p style="text-align:center;">Create an account? <a href="register.php">Register here</a></p>

</body>
</html>
