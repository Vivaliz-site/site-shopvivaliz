<?php
$conn = new mysqli("localhost", "usuario", "senha", "banco");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $instagram = $_POST['instagram'];
    $facebook = $_POST['facebook'];
    $whatsapp = $_POST['whatsapp'];

    $stmt = $conn->prepare("UPDATE settings SET instagram=?, facebook=?, whatsapp=? WHERE id=1");
    $stmt->bind_param("sss", $instagram, $facebook, $whatsapp);
    $stmt->execute();

    echo "<p>Salvo com sucesso!</p>";
}

$result = $conn->query("SELECT * FROM settings WHERE id=1");
$data = $result->fetch_assoc();
?>

<!DOCTYPE html>
<html>
<head>
    <title>Redes Sociais</title>
</head>
<body>

<h2>Painel de Redes Sociais</h2>

<form method="POST">
    <label>Instagram:</label><br>
    <input type="text" name="instagram" value="<?= $data['instagram'] ?>"><br><br>

    <label>Facebook:</label><br>
    <input type="text" name="facebook" value="<?= $data['facebook'] ?>"><br><br>

    <label>WhatsApp:</label><br>
    <input type="text" name="whatsapp" value="<?= $data['whatsapp'] ?>"><br><br>

    <button type="submit">Salvar</button>
</form>

</body>
</html>