-- Replace MariaDB-native UUID type with CHAR(36) for MySQL compatibility.
ALTER TABLE wl_userprefs
    MODIFY COLUMN uuid CHAR(36) NOT NULL;
