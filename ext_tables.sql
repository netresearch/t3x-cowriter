CREATE TABLE tx_t3cowriter_domain_model_prompts (
    title varchar(255) DEFAULT '' NOT NULL,
    prompt text DEFAULT '' NOT NULL
);

CREATE TABLE tx_t3cowriter_domain_model_contentelement (
    title varchar(255) DEFAULT '' NOT NULL,
    table varchar(255) DEFAULT '' NOT NULL,
    field text DEFAULT '' NOT NULL
);
