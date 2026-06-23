#!/usr/bin/env bash
# Capture live network config for Ubuntu autoinstall user-data.

sovereign_netplan_autoinstall_block() {
    local netplan_file
    for netplan_file in /etc/netplan/*.yaml; do
        [ -f "$netplan_file" ] || continue

        # Selectel / OpenStack: static IP bound to MAC in cloud-init netplan.
        if grep -qE 'macaddress:|set-name:' "$netplan_file" \
            && grep -qE '[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+/[0-9]+' "$netplan_file"; then
            echo "  network:"
            awk '/^network:/{found=1; next} found{print}' "$netplan_file"
            return 0
        fi

        if grep -q 'dhcp4:\s*true' "$netplan_file" 2>/dev/null; then
            echo "  network:"
            awk '/^network:/{found=1; next} found{print}' "$netplan_file"
            return 0
        fi
    done

    return 1
}

sovereign_capture_network_yaml() {
    if [ -n "${SOVEREIGN_NETWORK_YAML:-}" ]; then
        printf '%s\n' "$SOVEREIGN_NETWORK_YAML"
        return 0
    fi

    if sovereign_netplan_autoinstall_block; then
        return 0
    fi

    local iface gw4 addr4 dns_lines mac
    iface="$(ip -4 route show default 2>/dev/null | awk '{for (i = 1; i <= NF; i++) if ($i == "dev") { print $(i + 1); exit }}')"
    [ -n "$iface" ] || iface="$(ip -o link show | awk -F': ' '$2 !~ /^(lo|docker|veth|br-|wg)/ {print $2; exit}')"
    mac="$(ip link show "$iface" 2>/dev/null | awk '/link\/ether/ {print $2; exit}')"

    addr4="$(ip -4 -o addr show dev "$iface" scope global 2>/dev/null | awk '{print $4; exit}')"
    gw4="$(ip -4 route show default dev "$iface" 2>/dev/null | awk '{print $3; exit}')"

    if [ -n "$addr4" ] && [ -n "$gw4" ]; then
        dns_lines="$(grep -E '^nameserver ' /etc/resolv.conf 2>/dev/null | awk '{print $2}' | sed 's/^/            - /')"
        if [ -z "$dns_lines" ]; then
            dns_lines="            - 1.1.1.1
            - 8.8.8.8"
        fi
        if [ -n "$mac" ]; then
            cat <<EOF
  network:
    version: 2
    ethernets:
      ${iface}:
        match:
          macaddress: ${mac}
        set-name: ${iface}
        dhcp4: false
        dhcp6: ${SOVEREIGN_NETWORK_DHCP6:-false}
        addresses:
          - ${addr4}
        routes:
          - to: default
            via: ${gw4}
        nameservers:
          addresses:
${dns_lines}
EOF
        else
            cat <<EOF
  network:
    version: 2
    ethernets:
      ${iface}:
        dhcp4: false
        dhcp6: ${SOVEREIGN_NETWORK_DHCP6:-false}
        addresses:
          - ${addr4}
        routes:
          - to: default
            via: ${gw4}
        nameservers:
          addresses:
${dns_lines}
EOF
        fi
        return 0
    fi

    cat <<EOF
  network:
    version: 2
    ethernets:
      primary:
        match:
          name: "en*"
        dhcp4: true
        dhcp6: ${SOVEREIGN_NETWORK_DHCP6:-true}
        optional: true
      legacy:
        match:
          name: "eth*"
        dhcp4: true
        optional: true
EOF
}

sovereign_network_summary() {
    local iface mode ip gw mac
    iface="$(ip -4 route show default 2>/dev/null | awk '{for (i = 1; i <= NF; i++) if ($i == "dev") { print $(i + 1); exit }}')"
    ip="$(ip -4 -o addr show dev "${iface:-lo}" scope global 2>/dev/null | awk '{print $4}' | head -n1)"
    gw="$(ip -4 route show default 2>/dev/null | awk '{print $3; exit}')"
    mac="$(ip link show "${iface:-eth0}" 2>/dev/null | awk '/link\/ether/ {print $2; exit}')"
    if grep -qR 'dhcp4:\s*true' /etc/netplan/*.yaml 2>/dev/null; then
        mode=dhcp
    else
        mode=static
    fi
    printf 'mode=%s iface=%s ip=%s gw=%s mac=%s\n' "${mode}" "${iface:-unknown}" "${ip:-none}" "${gw:-none}" "${mac:-none}"
}
