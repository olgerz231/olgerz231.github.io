<?php
session_start();

if (!isset($_SESSION['pre_autenticacion']) || !$_SESSION['pre_autenticacion']['two_factor_required']) {
    header("Location: login.php");
    exit();
}

require_once 'vendor/autoload.php'; // Para Google Authenticator
use RobThree\Auth\TwoFactorAuth;

$tfa = new TwoFactorAuth('AttendSync');

$error = '';

// Si no tiene secret, generar uno nuevo
if (empty($_SESSION['pre_autenticacion']['two_factor_secret'])) {
    $_SESSION['pre_autenticacion']['two_factor_secret'] = $tfa->createSecret();
}

// Procesar código 2FA
if (isset($_POST['verificar_2fa'])) {
    $codigo = $_POST['codigo_2fa'];
    
    if (empty($codigo)) {
        $error = "Por favor ingrese el código de verificación";
    } else {
        if ($tfa->verifyCode($_SESSION['pre_autenticacion']['two_factor_secret'], $codigo)) {
            // Código válido, completar autenticación
            $_SESSION['id'] = $_SESSION['pre_autenticacion']['id'];
            $_SESSION['rol'] = $_SESSION['pre_autenticacion']['rol'];
            $_SESSION['email'] = $_SESSION['pre_autenticacion']['email'];
            $_SESSION['nombres'] = $_SESSION['pre_autenticacion']['nombres'];
            $_SESSION['usuario'] = $_SESSION['pre_autenticacion']['usuario'];
            
            // Si es la primera vez, guardar el secret en la base de datos
            if (!isset($_SESSION['pre_autenticacion']['two_factor_configured'])) {
                $db = getDBConnection();
                $stmt = $db->prepare("UPDATE usuarios SET two_factor_secret = ? WHERE id = ?");
                $stmt->bind_param("si", $_SESSION['pre_autenticacion']['two_factor_secret'], $_SESSION['pre_autenticacion']['id']);
                $stmt->execute();
                $stmt->close();
            }
            
            unset($_SESSION['pre_autenticacion']);
            
            $redirectPage = ($_SESSION['rol'] == 'administrador') ? 'panel_administrador.php' : "panel_$_SESSION[rol].php";
            header("Location: $redirectPage");
            exit();
        } else {
            $error = "Código de verificación incorrecto";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificación en Dos Pasos - AttendSync</title>
    <style>
        body {
            font-family: 'Montserrat', sans-serif;
            background-color: #f0f4f3;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }
        .container {
            background-color: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
            width: 400px;
            max-width: 90%;
            text-align: center;
        }
        h2 {
            margin-bottom: 20px;
            color: #3ab397;
        }
        p {
            margin-bottom: 20px;
        }
        .qr-code {
            margin: 20px auto;
            padding: 10px;
            background-color: white;
            display: inline-block;
        }
        input[type="text"] {
            width: 100%;
            padding: 10px;
            margin: 10px 0;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 16px;
            text-align: center;
        }
        button {
            width: 100%;
            padding: 12px;
            background-color: #3ab397;
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            cursor: pointer;
            margin-top: 10px;
        }
        button:hover {
            background-color: #2d8a7a;
        }
        .error-message {
            color: red;
            margin-bottom: 15px;
        }
        .setup-instructions {
            text-align: left;
            margin: 20px 0;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>Verificación en Dos Pasos</h2>
        
        <?php if(!isset($_SESSION['pre_autenticacion']['two_factor_configured'])): ?>
            <p>Para completar la configuración de seguridad, escanee el siguiente código QR con su aplicación de autenticación:</p>
            
            <div class="qr-code">
                <?php 
                $qrCodeUrl = $tfa->getQRCodeImageAsDataUri(
                    'AttendSync (' . $_SESSION['pre_autenticacion']['email'] . ')', 
                    $_SESSION['pre_autenticacion']['two_factor_secret']
                );
                ?>
                <img src="<?php echo $qrCodeUrl; ?>" alt="Código QR para autenticación de dos factores">
            </div>
            
            <div class="setup-instructions">
                <p><strong>Instrucciones:</strong></p>
                <ol>
                    <li>Descargue una aplicación de autenticación como Google Authenticator o Authy</li>
                    <li>Escanee el código QR con la aplicación</li>
                    <li>Ingrese el código de 6 dígitos que muestra la aplicación</li>
                </ol>
            </div>
        <?php else: ?>
            <p>Por favor ingrese el código de verificación de 6 dígitos de su aplicación de autenticación:</p>
        <?php endif; ?>
        
        <?php if(!empty($error)): ?>
            <div class="error-message" role="alert"><?php echo $error; ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <input type="text" name="codigo_2fa" placeholder="Código de 6 dígitos" required autocomplete="off" maxlength="6">
            <button type="submit" name="verificar_2fa">VERIFICAR</button>
        </form>
    </div>
</body>
</html>