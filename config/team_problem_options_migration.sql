-- Saved problem choices are shared only within the same IT or AV team.
CREATE TABLE IF NOT EXISTS team_problem_options (
    id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
    department VARCHAR(20) NOT NULL,
    problem_text VARCHAR(255) NOT NULL,
    created_by INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_team_problem (department, problem_text),
    KEY index_team_problem_department (department)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
