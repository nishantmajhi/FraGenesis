<?php
    function invalidQuery(): void
    {
        http_response_code(400);
?>


        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8" />
            <meta name="viewport" content="width=device-width, initial-scale=1.0" />
            <title>Invalid Query | FraGenesis</title>
            <style>
                :root {
                    --primary-color: #4caf50;
                    --dark-green: #003838;
                }

                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }

                body {
                    font-family: sans-serif, system-ui;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    min-height: 100dvh;
                    background: linear-gradient(135deg, var(--dark-green) 0%, var(--primary-color) 100%);
                    color: #181818;
                }

                main {
                    text-align: center;
                    background: white;
                    padding: 2.5rem;
                    border-radius: 0.75rem;
                    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
                    width: max-content;
                }

                h1 {
                    font-size: 4.5rem;
                    font-weight: bold;
                    color: var(--primary-color);
                    margin-bottom: 1.25rem;
                }

                h2 {
                    font-size: 1.5rem;
                    color: #333;
                    margin-bottom: 1rem;
                }

                p {
                    font-size: 1rem;
                    color: #666;
                    margin-bottom: 2rem;
                    line-height: 1.6;
                }

                details {
                    background: #f5f5f5;
                    padding: 1rem;
                    border-left: 0.25rem solid var(--primary-color);
                    text-align: left;
                    margin-bottom: 2rem;
                    border-radius: 0.25rem;
                    font-size: 0.8125rem;
                    color: #555;
                }
                
                details summary {
                    font-weight: bold;
                    margin-bottom: 0.5rem;
                    color: #333;
                }
                
                details ul {
                    padding-left: 1rem;
                    font-family: 'Courier New', monospace;
                }

                button {
                    display: inline-block;
                    padding: 0.75rem 2rem;
                    background: var(--primary-color);
                    color: white;
                    text-decoration: none;
                    border-radius: 0.375rem;
                    border: none;
                    cursor: pointer;
                    font-size: 1rem;
                    transition: background-color 0.3s ease;
                }

                button:hover {
                    background-color: var(--dark-green);
                }
            </style>
        </head>
        <body>
            <main>
                <h1>400</h1>
                <h2>Invalid Query</h2>
                <p>
                    The request you made could not be processed.<br /> Please check your input and try again.
                </p>
                <details open>
                    <summary>Possible reasons:</summary>
                    <ul>
                        <li>Missing required parameters (grade, fullMarks, topics)</li>
                        <li>Invalid parameter values</li>
                        <li>Incorrect request method</li>
                    </ul>
                </details>
                <button onclick="history.back()">Go Back</button>
            </main>
        </body>
        </html>


<?php
        exit();
    }
?>