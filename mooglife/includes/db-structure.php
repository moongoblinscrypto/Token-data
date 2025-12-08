<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Schema Details</title>
    <style>
        /* General styles for screen display */
        body {
            font-family: sans-serif;
            margin: 20px;
            line-height: 1.6;
            color: #333;
        }
        h1, h2 {
            color: #000;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
            box-shadow: 0 2px 3px rgba(0,0,0,0.1);
        }
        th, td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: left;
            word-wrap: break-word;
        }
        th {
            background-color: #f2f2f2;
            font-weight: bold;
        }

        /* CSS for printing */
        @media print {
            body {
                font-size: 11px; /* Smaller font for more content per page */
                color: black;
            }
            table {
                border-collapse: collapse;
                width: 100%;
            }
            th, td {
                border: 1px solid #000; /* Ensure borders print in black */
                padding: 5px;
            }
            /* Hide elements that are not necessary for a printed report, if any */
        }
    </style>
</head>
<body>

    <?php
    // Database connection variables
    $host = 'localhost';
    $dbname = 'goblinshq'; // Replace with your database name
    $user = 'root';   // Replace with your username
    $pass = '';   // Replace with your password

    try {
        // Establish a PDO connection
        $pdo = new PDO("mysql:host=$host;dbname=$dbname", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        echo "<h1>Database Schema: " . htmlspecialchars($dbname) . "</h1>";
        echo "<div class='schema-container'>";

        // Query the information_schema to get all table names in the database
        $tables_query = $pdo->query("SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA = '$dbname' ORDER BY TABLE_NAME");
        $tables = $tables_query->fetchAll(PDO::FETCH_COLUMN);

        if (empty($tables)) {
            echo "<p>No tables found in the database.</p>";
        }

        foreach ($tables as $table_name) {
            echo "<h2>Table: " . htmlspecialchars($table_name) . "</h2>";
            echo "<table>";
            echo "<thead><tr>
                    <th>Column Name</th>
                    <th>Data Type</th>
                    <th>Length</th>
                    <th>Key</th>
                    <th>Nullable</th>
                    <th>Default</th>
                    <th>Extra Info</th>
                  </tr></thead>";
            echo "<tbody>";

            // Query the information_schema to get column details for the current table
            $columns_query = $pdo->prepare("
                SELECT 
                    COLUMN_NAME, 
                    DATA_TYPE, 
                    CHARACTER_MAXIMUM_LENGTH AS LENGTH, 
                    COLUMN_KEY AS `KEY`, 
                    IS_NULLABLE AS NULLABLE,
                    COLUMN_DEFAULT AS `DEFAULT`,
                    EXTRA AS `EXTRA_INFO`
                FROM 
                    information_schema.columns 
                WHERE 
                    TABLE_SCHEMA = :dbname AND TABLE_NAME = :table_name
                ORDER BY 
                    ORDINAL_POSITION
            ");
            $columns_query->bindParam(':dbname', $dbname);
            $columns_query->bindParam(':table_name', $table_name);
            $columns_query->execute();
            $columns = $columns_query->fetchAll(PDO::FETCH_ASSOC);

            foreach ($columns as $column) {
                echo "<tr>";
                foreach ($column as $value) {
                    // Use a non-breaking space if value is null for better table rendering
                    echo "<td>" . htmlspecialchars($value ?? '&nbsp;') . "</td>";
                }
                echo "</tr>";
            }

            echo "</tbody></table>";
        }

        echo "</div>";

    } catch (PDOException $e) {
        // Display connection errors in the body of the HTML document
        echo "<div style='color: red; padding: 10px; border: 1px solid red;'>";
        echo "Connection failed: " . htmlspecialchars($e->getMessage());
        echo "</div>";
    }
    ?>

</body>
</html>
