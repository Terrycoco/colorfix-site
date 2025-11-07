<?php
require_once 'db.php';

ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $groups = $pdo->query("SELECT id, attribute_type, value_min, value_max FROM color_attribute_groups")->fetchAll(PDO::FETCH_ASSOC);

    $count = 0;

    foreach ($groups as $group) {
        $column = ($group['attribute_type'] === 'lightness') ? 'hcl_l' : 'hcl_c';
        $target = ($group['attribute_type'] === 'lightness') ? 'lightness_group_id' : 'chroma_group_id';

        $sql = "
            UPDATE colors
            SET $target = :group_id
            WHERE $column >= :min AND $column <= :max
              AND $column IS NOT NULL
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'group_id' => $group['id'],
            'min' => $group['value_min'],
            'max' => $group['value_max']
        ]);

        $count += $stmt->rowCount();
    }

    echo "Successfully updated $count color rows.";

} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage();
}
