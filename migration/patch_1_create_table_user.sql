--@Version 2 "Create 'user' table"

--@Up
CREATE TABLE IF NOT EXISTS user (
	user_id INT AUTO_INCREMENT PRIMARY KEY,
	name VARCHAR(255) NOT NULL,
	created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
	updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

--@Down
DROP TABLE user;
