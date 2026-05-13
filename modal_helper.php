<?php
function showModalMessage(string $title, string $message, string $buttonText = 'OK', string $buttonLink = 'javascript:history.back()'): void
{
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title><?= htmlspecialchars($title) ?></title>
        <style>
            body {
                margin: 0;
                font-family: Arial, sans-serif;
                background: rgba(0, 0, 0, 0.45);
            }

            .modal-page {
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
                box-sizing: border-box;
            }

            .modal-box {
                width: 100%;
                max-width: 430px;
                background: #fff;
                border: 2px solid #000;
                border-radius: 20px;
                padding: 24px;
                box-sizing: border-box;
                text-align: center;
                box-shadow: 0 12px 30px rgba(0,0,0,0.20);
            }

            .modal-box h2 {
                margin: 0 0 12px;
                color: #A70202;
                font-size: 28px;
            }

            .modal-box p {
                margin: 0 0 20px;
                color: #222;
                line-height: 1.6;
                font-size: 16px;
            }

            .modal-btn {
                display: inline-block;
                padding: 12px 20px;
                border-radius: 999px;
                background: #A70202;
                color: #fff;
                text-decoration: none;
                font-weight: bold;
                border: none;
                cursor: pointer;
            }

            .modal-btn:hover {
                background: #7f0101;
            }

            @media (max-width: 600px) {
                .modal-box {
                    padding: 18px;
                    border-radius: 16px;
                }

                .modal-box h2 {
                    font-size: 24px;
                }

                .modal-box p {
                    font-size: 14px;
                }
            }
        </style>
    </head>
    <body>
        <div class="modal-page">
            <div class="modal-box">
                <h2><?= htmlspecialchars($title) ?></h2>
                <p><?= htmlspecialchars($message) ?></p>
                <a href="<?= htmlspecialchars($buttonLink) ?>" class="modal-btn"><?= htmlspecialchars($buttonText) ?></a>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit();
}
?>