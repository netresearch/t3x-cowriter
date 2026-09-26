#
# Prompts that editors save from the Cowriter dialog
#
CREATE TABLE tx_cowriter_prompt (
	be_user int(11) unsigned DEFAULT '0' NOT NULL,
	title varchar(255) DEFAULT '' NOT NULL,
	instruction mediumtext,
	shared tinyint(1) unsigned DEFAULT '0' NOT NULL,
	approved tinyint(1) unsigned DEFAULT '0' NOT NULL
);
