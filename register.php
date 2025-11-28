<?php include('db.php'); ?>

<!DOCTYPE html>
<html>
<head>
    <title>Register</title>
    <link rel="stylesheet" href="css/form.css">
</head>

<body>
<h2 style="text-align:center;">Registration Form</h2>

<?php
$xml = simplexml_load_file("structure.xml");

// Load CSS style mappings
$styles = [];
foreach ($xml->styles->style as $st) {
    $styles[(string)$st['name']] = (string)$st;
}
?>

<div class="<?php echo $styles['form-wrapper']; ?>">
    <form action="" method="POST">

        <?php
        foreach ($xml->form as $form) {
            if ($form['name'] == 'register') {

                foreach ($form->field as $field) {

                    $label  = (string)$field->label;
                    $name   = (string)$field->name;
                    $type   = (string)$field->type;

                    $label_class = $styles['label'];
                    $input_class = $styles[(string)$field->css];

                    $placeholder = isset($field->placeholder) ? (string)$field->placeholder : '';
                    $pattern     = isset($field->pattern) ? (string)$field->pattern : '';

                    echo "<label class='{$label_class}'>{$label}</label><br>";

                    echo "<input type='{$type}' 
                        name='{$name}'
                        class='{$input_class}'
                        placeholder='{$placeholder}'"
                        . (!empty($pattern) ? " pattern='{$pattern}'" : "") .
                        " required><br>";
                }

                // Load button class from XML
                $button_class = $styles[(string)$form->{'button-class'}];
            }
        }
        ?>

        <input type="submit" name="register" value="Register" class="<?php echo $button_class; ?>">
    </form>
</div>

<p style="text-align:center;">Already have an account? <a href="login.php">Login here</a></p>

<?php
// --- Encryption Functions ---
function encrypt_data($data) {
    $key = 'mysecretkey12345';
    return openssl_encrypt($data, 'AES-128-ECB', $key);
}

function decrypt_data($data) {
    $key = 'mysecretkey12345';
    return openssl_decrypt($data, 'AES-128-ECB', $key);
}

// --- Registration Logic ---
if (isset($_POST['register'])) {

    $username = $_POST['username'];
    $email    = $_POST['email'];
    $password = password_hash($_POST['password'], PASSWORD_DEFAULT);

    // Validate Gmail format
    if (!preg_match("/^[a-zA-Z0-9._%+-]+@gmail\.com$/", $email)) {
        echo "❌ Email must be a valid Gmail address (example@gmail.com)";
        exit;
    }

    // Encrypt username and email
    $username_encrypted = encrypt_data($username);
    $email_encrypted    = encrypt_data($email);

    // Check duplicates
    $check = $conn->query("SELECT * FROM users");
    $exists = false;
    $duplicate_type = '';

    while ($row = $check->fetch_assoc()) {
        if (decrypt_data($row['username_encrypted']) === $username) {
            $exists = true;
            $duplicate_type = 'username';
            break;
        }
        if (decrypt_data($row['email_encrypted']) === $email) {
            $exists = true;
            $duplicate_type = 'email';
            break;
        }
    }

    if ($exists) {
        echo "❌ This $duplicate_type is already registered!";
    } else {
        $sql = "INSERT INTO users (username_encrypted, password_hashed, email_encrypted) 
                VALUES ('$username_encrypted', '$password', '$email_encrypted')";

        if ($conn->query($sql) === TRUE) {
            echo "✅ Registration successful!";
        } else {
            echo "Error: " . $conn->error;
        }
    }
}
?>
</body>
</html>
