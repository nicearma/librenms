<?php

if ($device['status'] == '0') {  $class = "alert-danger"; } else {   $class = ""; }
if ($device['ignore'] == '1')
{
  $class = "div-ignore-alert";
  if ($device['status'] == '1')
  {
    $class = "alert-warning";
  }
}
if ($device['disabled'] == '1')
{
  $class = "alert-info";
}

$type = strtolower($device['os']);

$image = getImage($device);

echo('
            <tr bgcolor="'.$device_colour.'" class="alert '.$class.'">
             <td width="40" align=center valign=middle style="padding: 21px;">'.$image.'</td>'
        . '<td valign=middle style="padding: 0 15px;"><span style="font-size: 20px;">');

             if(empty($vars['vrf-lite'])){
                 echo generate_device_link($device) ;
             }else{
                 echo generate_device_link($device,$device['hostname'].':'.$vars['vrf-lite'],array('device'=>$device['device_id'],'vrf-lite'=>$vars['vrf-lite'])) ;
             }    
             
             echo ('</span><br />' . $device['location'] . '</td>
             <td>');

  if (isset($config['os'][$device['os']]['over']))
{
  $graphs = $config['os'][$device['os']]['over'];
}
elseif (isset($device['os_group']) && isset($config['os'][$device['os_group']]['over']))
{
  $graphs = $config['os'][$device['os_group']]['over'];
}
else
{
  $graphs = $config['os']['default']['over'];
}

$graph_array = array();
$graph_array['height'] = "100";
$graph_array['width']  = "310";
$graph_array['to']     = $config['time']['now'];
$graph_array['device'] = $device['device_id'];
$graph_array['type']   = "device_bits";
$graph_array['from']   = $config['time']['day'];
$graph_array['legend'] = "no";
$graph_array['popup_title'] = $descr;

$graph_array['height'] = "45";
$graph_array['width']  = "150";
$graph_array['bg']     = "FFFFFF00";

foreach ($graphs as $entry)
{
  if ($entry['graph'])
  {
    $graph_array['type']   = $entry['graph'];

    echo("<div style='float: right; text-align: center; padding: 1px 5px; margin: 0 1px; ' class='rounded-5px'>");
    print_graph_popup($graph_array);
    echo("<div style='font-weight: bold; font-size: 7pt; margin: -3px;'>".$entry['text']."</div>");
    echo("</div>");
  }
}

unset($graph_array);

echo('</td>
   </tr>');

?>
