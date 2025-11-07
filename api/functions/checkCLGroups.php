<?php function checkCLGroups(array $color, array $groupDefs): array {
    $l = (float) $color['hcl_l'];
    $c = (float) $color['hcl_c'];

    $l_group = null;
    foreach ($groupDefs['lightness'] as $g) {
        if ($l >= $g['value_min'] && $l < $g['value_max']) {
            $l_group = $g['group_name'];
            break;
        }
    }

    $c_group = null;
    foreach ($groupDefs['chroma'] as $g) {
        if ($c >= $g['value_min'] && $c < $g['value_max']) {
            $c_group = $g['group_name'];
            break;
        }
    }

    return ['l_group' => $l_group, 'c_group' => $c_group];
}

?>