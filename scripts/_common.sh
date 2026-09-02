#!/bin/bash

#=================================================
# COMMON VARIABLES AND HELPERS
#=================================================

# Apply the LDAP authentication configuration directly in Moodle's database.
#
# Moodle exposes no CLI to configure the LDAP auth plugin, so we write the
# relevant rows straight into the (MariaDB) database. Table names have no schema
# prefix on MySQL/MariaDB (unlike the previous PostgreSQL "public." prefix).
#
# Requires: $admin (Moodle admin username), a reachable database ($db_name).
_moodle_configure_ldap() {
    local statements=(
        "UPDATE mdl_config SET value='ldap,email' WHERE name='auth';"
        "UPDATE mdl_config_plugins SET value='ldap://127.0.0.1/' WHERE plugin='auth_ldap' AND name='host_url';"
        "UPDATE mdl_config_plugins SET value='uid' WHERE plugin='auth_ldap' AND name='user_attribute';"
        "UPDATE mdl_config_plugins SET value='ou=users,dc=yunohost,dc=org' WHERE plugin='auth_ldap' AND name='contexts';"
        "UPDATE mdl_config_plugins SET value='givenName' WHERE plugin='auth_ldap' AND name='field_map_firstname';"
        "UPDATE mdl_config_plugins SET value='sn' WHERE plugin='auth_ldap' AND name='field_map_lastname';"
        "UPDATE mdl_config_plugins SET value='mail' WHERE plugin='auth_ldap' AND name='field_map_email';"
        "UPDATE mdl_config_plugins SET value='onlogin' WHERE plugin='auth_ldap' AND (name='field_updatelocal_firstname' OR name='field_updatelocal_lastname' OR name='field_updatelocal_email');"
        "UPDATE mdl_config_plugins SET value='locked' WHERE plugin='auth_ldap' AND (name='field_lock_firstname' OR name='field_lock_lastname' OR name='field_lock_email');"
        "UPDATE mdl_user SET auth='ldap' WHERE username='$admin';"
    )

    local statement
    for statement in "${statements[@]}"; do
        ynh_mysql_db_shell "$db_name" <<< "$statement"
    done
}
