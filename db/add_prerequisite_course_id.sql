USE [university_db];
GO

IF COL_LENGTH('dbo.COURSE', 'prerequisite_course_id') IS NULL
BEGIN
    ALTER TABLE dbo.COURSE
    ADD prerequisite_course_id INT NULL;
END
GO

IF NOT EXISTS (
    SELECT 1
    FROM sys.foreign_keys
    WHERE name = 'FK_COURSE_PREREQUISITE_COURSE'
)
BEGIN
    ALTER TABLE dbo.COURSE
    ADD CONSTRAINT FK_COURSE_PREREQUISITE_COURSE
    FOREIGN KEY (prerequisite_course_id)
    REFERENCES dbo.COURSE(course_id);
END
GO
