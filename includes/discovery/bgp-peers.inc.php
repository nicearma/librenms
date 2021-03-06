<?php

global $debug;


if ($config['enable_bgp']) {
    // Discover BGP peers

    echo("BGP Sessions : ");


    //WHAT HAPPEN IF THE CONTEXT_NAME CHANGE AND THE VALUE IS ALREADY IN THE DATBABASE?????!!!!!

    if (key_exists('vrf_lite_cisco', $device) && (count($device['vrf_lite_cisco']) != 0)) {
        $vrfs_lite_cisco = $device['vrf_lite_cisco'];
    } else {
        $vrfs_lite_cisco = array(array('context_name' => null));
    }

    $bgpLocalAs = trim(snmp_walk($device, ".1.3.6.1.2.1.15.2", "-Oqvn", "BGP4-MIB", $config['mibdir']));

    if (is_numeric($bgpLocalAs)) {
        echo("AS$bgpLocalAs ");

        if ($bgpLocalAs != $device['bgpLocalAs']) {
            dbUpdate(array('bgpLocalAs' => $bgpLocalAs), 'devices', 'device_id=?', array($device['device_id']));
            echo("Updated AS ");
        }
    } else {
        echo("No BGP on host");
        if ($device['bgpLocalAs']) {
            dbUpdate(array('bgpLocalAs' => 'NULL'), 'devices', 'device_id=?', array($device['device_id']));
            echo(" (Removed ASN) ");
        }
    }


    foreach ($vrfs_lite_cisco as $vrf) {
        
        $device['context_name']=$vrf['context_name'];

        if (is_numeric($bgpLocalAs)) {


            $peers_data = snmp_walk($device, "BGP4-MIB::bgpPeerRemoteAs", "-Oq", "BGP4-MIB", $config['mibdir']);
            if ($debug) {
                echo("Peers : $peers_data \n");
            }
            $peers = trim(str_replace("BGP4-MIB::bgpPeerRemoteAs.", "", $peers_data));

            foreach (explode("\n", $peers) as $peer) {
                list($peer_ip, $peer_as) = explode(" ", $peer);

                if ($peer && $peer_ip != "0.0.0.0") {
                    if ($debug) {
                        echo("Found peer $peer_ip (AS$peer_as)\n");
                    }
                    $peerlist[] = array('ip' => $peer_ip, 'as' => $peer_as);
                }
            } # Foreach

            
            //TODO: see what happen with CONTEXT in OS JUNOS
            if ($device['os'] == "junos") {
                // Juniper BGP4-V2 MIB
                // FIXME: needs a big cleanup! also see below.
                // FIXME: is .0.ipv6 the only possible value here?
                $result = snmp_walk($device, "jnxBgpM2PeerRemoteAs.0.ipv6", "-Onq", "BGP4-V2-MIB-JUNIPER", $config['install_dir'] . "/mibs/junos");
                $peers = trim(str_replace(".1.3.6.1.4.1.2636.5.1.1.2.1.1.1.13.0.", "", $result));
                foreach (explode("\n", $peers) as $peer) {
                    list($peer_ip_snmp, $peer_as) = explode(" ", $peer);

                    # Magic! Basically, takes SNMP form and finds peer IPs from the walk OIDs.
                    $peer_ip = Net_IPv6::compress(snmp2ipv6(implode('.', array_slice(explode('.', $peer_ip_snmp), count(explode('.', $peer_ip_snmp)) - 16))));

                    if ($peer) {
                        if ($debug)
                            echo("Found peer $peer_ip (AS$peer_as)\n");
                        $peerlist[] = array('ip' => $peer_ip, 'as' => $peer_as);
                    }
                } # Foreach
            } # OS junos
        }
        // Process disovered peers

        if (isset($peerlist)) {
            foreach ($peerlist as $peer) {
                $astext = get_astext($peer['as']);

                if (dbFetchCell("SELECT COUNT(*) from `bgpPeers` WHERE device_id = ? AND `bgpPeerIdentifier` = ? AND `context_name` = ?", array($device['device_id'], $peer['ip'],$device['context_name'])) < '1') {
                    $add = dbInsert(array('device_id' => $device['device_id'], 'bgpPeerIdentifier' => $peer['ip'], 'bgpPeerRemoteAs' => $peer['as'],'context_name'=>$device['context_name']), 'bgpPeers');
                    echo("+");
                } else {
                    $update = dbUpdate(array('bgpPeerRemoteAs' => $peer['as'], 'astext' => mres($astext)), 'bgpPeers', 'device_id=? AND bgpPeerIdentifier=?', array($device['device_id'], $peer['ip']));
                    echo(".");
                }

                if ($device['os_group'] == "cisco" || $device['os'] == "junos") {

                    if ($device['os_group'] == "cisco") {
                        // Get afi/safi and populate cbgp on cisco ios (xe/xr)
                        unset($af_list);

                        $af_data = snmp_walk($device, "cbgpPeerAddrFamilyName." . $peer['ip'], "-OsQ", "CISCO-BGP4-MIB", $config['mibdir']);
                        if ($debug) {
                            echo("afi data :: $af_data \n");
                        }

                        $afs = trim(str_replace("cbgpPeerAddrFamilyName." . $peer['ip'] . ".", "", $af_data));
                        foreach (explode("\n", $afs) as $af) {
                            if ($debug) {
                                echo("AFISAFI = $af\n");
                            }
                            list($afisafi, $text) = explode(" = ", $af);
                            list($afi, $safi) = explode(".", $afisafi);
                            if ($afi && $safi) {
                                $af_list[$afi][$safi] = 1;
                                if (dbFetchCell("SELECT COUNT(*) from `bgpPeers_cbgp` WHERE `device_id` = ? AND `bgpPeerIdentifier` = ?, AND `afi`=? AND `safi`=? and `context_name`=?", array($device['device_id'], $peer['ip'], $afi, $safi,$device['context_name'])) == 0) {
                                    dbInsert(array('device_id' => $device['device_id'], 'bgpPeerIdentifier' => $peer['ip'], 'afi' => $afi, 'safi' => $safi,'context_name'=>$device['context_name']), 'bgpPeers_cbgp');
                                }
                            }
                        }
                    } # os_group=cisco

                    
                    
                    //TODO: See what happen with CONTEXT for the OS JUNOS 
                    if ($device['os'] == "junos") {
                        $safis[1] = "unicast";
                        $safis[2] = "multicast";

                        if (!isset($j_peerIndexes)) {
                            $j_bgp = snmpwalk_cache_multi_oid($device, "jnxBgpM2PeerTable", $jbgp, "BGP4-V2-MIB-JUNIPER", $config['install_dir'] . "/mibs/junos");

                            foreach ($j_bgp as $index => $entry) {
                                switch ($entry['jnxBgpM2PeerRemoteAddrType']) {
                                    case 'ipv4':
                                        $ip = long2ip(hexdec($entry['jnxBgpM2PeerRemoteAddr']));
                                        if ($debug) {
                                            echo("peerindex for ipv4 $ip is " . $entry['jnxBgpM2PeerIndex'] . "\n");
                                        }
                                        $j_peerIndexes[$ip] = $entry['jnxBgpM2PeerIndex'];
                                        break;
                                    case 'ipv6':
                                        $ip6 = trim(str_replace(' ', '', $entry['jnxBgpM2PeerRemoteAddr']), '"');
                                        $ip6 = substr($ip6, 0, 4) . ':' . substr($ip6, 4, 4) . ':' . substr($ip6, 8, 4) . ':' . substr($ip6, 12, 4) . ':' . substr($ip6, 16, 4) . ':' . substr($ip6, 20, 4) . ':' . substr($ip6, 24, 4) . ':' . substr($ip6, 28, 4);
                                        $ip6 = Net_IPv6::compress($ip6);
                                        if ($debug) {
                                            echo("peerindex for ipv6 $ip6 is " . $entry['jnxBgpM2PeerIndex'] . "\n");
                                        }
                                        $j_peerIndexes[$ip6] = $entry['jnxBgpM2PeerIndex'];
                                        break;
                                    default:
                                        echo("HALP? Don't know RemoteAddrType " . $entry['jnxBgpM2PeerRemoteAddrType'] . "!\n");
                                        break;
                                }
                            }
                        }

                        if (!isset($j_afisafi)) {
                            $j_prefixes = snmpwalk_cache_multi_oid($device, "jnxBgpM2PrefixCountersTable", $jbgp, "BGP4-V2-MIB-JUNIPER", $config['install_dir'] . "/mibs/junos");
                            foreach (array_keys($j_prefixes) as $key) {
                                list($index, $afisafi) = explode('.', $key, 2);
                                $j_afisafi[$index][] = $afisafi;
                            }
                        }

                        foreach ($j_afisafi[$j_peerIndexes[$peer['ip']]] as $afisafi) {
                            list ($afi, $safi) = explode('.', $afisafi);
                            $safi = $safis[$safi];
                            $af_list[$afi][$safi] = 1;
                            if (dbFetchCell("SELECT COUNT(*) from `bgpPeers_cbgp` WHERE device_id = ? AND bgpPeerIdentifier = ?, AND afi=? AND safi=?", array($device['device_id'], $peer['ip'], $afi, $safi)) == 0) {
                                dbInsert(array('device_id' => $device['device_id'], 'bgpPeerIdentifier' => $peer['ip'], 'afi' => $afi, 'safi' => $safi), 'bgpPeers_cbgp');
                            }
                        }
                    } # os=junos

                    $af_query = "SELECT * FROM bgpPeers_cbgp WHERE `device_id` = '" . $device['device_id'] . "' AND `bgpPeerIdentifier` = '" . $peer['ip'] . "' AND  `context_name` = '" . $device['context_name'] . "'";
                    foreach (dbFetchRows($af_query) as $entry) {
                        $afi = $entry['afi'];
                        $safi = $entry['safi'];
                        if (!$af_list[$afi][$safi]) {
                            dbDelete('bgpPeers_cbgp', '`device_id` = ? AND `bgpPeerIdentifier` = ? AND afi=? AND safi=? AND `context_name`=?', array($device['device_id'], $peer['ip'], $afi, $safi, $device['context_name']));
                        }
                    } # AF list
                } # os=cisco|junos
            } # Foreach

            unset($j_afisafi);
            unset($j_prefixes);
            unset($j_bgp);
            unset($j_peerIndexes);
        } # isset
        // Delete removed peers

        $sql = "SELECT * FROM bgpPeers AS B, devices AS D WHERE B.device_id = D.device_id AND D.device_id = '" . $device['device_id'] . "' AND  `context_name` = '" . $device['context_name'] . "'";

        foreach (dbFetchRows($sql) as $entry) {
            unset($exists);
            $i = 0;

            while ($i < count($peerlist) && !isset($exists)) {
                if ($peerlist[$i]['ip'] == $entry['bgpPeerIdentifier']) {
                    $exists = 1;
                }
                $i++;
            }

            if (!isset($exists)) {
                dbDelete('bgpPeers', '`bgpPeer_id` = ?', array($entry['bgpPeer_id']));
                dbDelete('bgpPeers_cbgp', '`bgpPeer_id` = ?', array($entry['bgpPeer_id']));
                echo("-");
            }
        }

        unset($peerlist);

        echo("\n");
        unset($device['context_name']);
    }
    unset($device['context_name']);
    unset($vrfs_c);
    
    
}
?>
