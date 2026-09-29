CREATE TABLE IF NOT EXISTS stored_images (
    school_id BIGINT UNSIGNED NOT NULL,
    name CHAR(44) NOT NULL,
    contents MEDIUMBLOB NOT NULL,
    PRIMARY KEY (school_id, name),
    FOREIGN KEY (school_id) REFERENCES schools(id)
) ENGINE=InnoDB;
