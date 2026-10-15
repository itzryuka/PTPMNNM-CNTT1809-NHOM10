-- Chạy trong database lab riêng; không dùng database production.
CREATE TABLE IF NOT EXISTS events (
    id integer PRIMARY KEY,
    title varchar(160) NOT NULL,
    category varchar(30) NOT NULL,
    capacity integer NOT NULL CHECK (capacity > 0)
);
CREATE TABLE IF NOT EXISTS registrations (
    id bigserial PRIMARY KEY,
    event_id integer NOT NULL REFERENCES events(id),
    name varchar(120) NOT NULL CHECK (length(trim(name)) > 0),
    email varchar(200) NOT NULL CHECK (email = lower(trim(email))),
    created_at timestamptz NOT NULL DEFAULT now(),
    UNIQUE (event_id, email)
);
INSERT INTO events (id,title,category,capacity) VALUES
(1,'PHP Open Day','tech',10),(2,'Design Workshop','design',5),
(3,'Git Clinic','tech',20) ON CONFLICT (id) DO NOTHING;
