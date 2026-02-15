# OWASP Top 10 Security Risks (2021)

## 1. Broken Access Control
**Description**: Failures related to authorization. Users can act outside their intended permissions.

**Examples**:
- Accessing other users' data by changing URL parameters
- Viewing or editing someone else's account
- Elevation of privilege (acting as admin without being one)
- CORS misconfiguration allowing unauthorized API access

**Prevention**:
- Deny by default
- Implement access control checks consistently
- Enforce record ownership
- Disable web server directory listing
- Log access control failures and alert admins

## 2. Cryptographic Failures
**Description**: Failures related to cryptography (or lack thereof) that lead to sensitive data exposure.

**Examples**:
- Transmitting data in clear text (HTTP, SMTP, FTP)
- Using old or weak cryptographic algorithms
- Default or weak crypto keys
- Missing encryption for sensitive data

**Prevention**:
- Classify data and apply controls per classification
- Don't store sensitive data unnecessarily
- Encrypt all data at rest and in transit
- Use up-to-date strong algorithms and keys
- Disable caching for sensitive data

## 3. Injection
**Description**: Hostile data sent to an interpreter as part of a command or query.

**Types**:
- SQL Injection
- NoSQL Injection
- OS Command Injection
- LDAP Injection
- XPath Injection

**Prevention**:
- Use parameterized queries (prepared statements)
- Use ORM frameworks safely
- Validate and sanitize all input
- Escape special characters
- Use LIMIT and other SQL controls

## 4. Insecure Design
**Description**: Missing or ineffective control design.

**Examples**:
- Lack of rate limiting allowing credential stuffing
- No anti-automation controls
- Missing security requirements and constraints

**Prevention**:
- Establish and use secure development lifecycle
- Use threat modeling
- Write unit and integration tests for security
- Use reference architectures and design patterns

## 5. Security Misconfiguration
**Description**: Missing security hardening or improperly configured permissions.

**Examples**:
- Default accounts and passwords
- Detailed error messages revealing sensitive information
- Missing security headers
- Outdated software
- Unnecessary features enabled

**Prevention**:
- Implement secure installation processes
- Minimal platform without unnecessary features
- Review and update configurations
- Automated process to verify configuration
- Segmented application architecture

## 6. Vulnerable and Outdated Components
**Description**: Using components with known vulnerabilities.

**Examples**:
- Not knowing versions of all components
- Vulnerable or unsupported software
- Not scanning for vulnerabilities regularly
- Not fixing or upgrading dependencies

**Prevention**:
- Remove unused dependencies and features
- Continuously inventory versions
- Monitor CVE and security bulletins
- Obtain components from official sources
- Use automated tools (Snyk, Dependabot, etc.)

## 7. Identification and Authentication Failures
**Description**: Failures in confirming user identity, authentication, and session management.

**Examples**:
- Permits automated attacks like credential stuffing
- Weak password requirements
- Uses plain text or weakly hashed passwords
- Missing or ineffective multi-factor authentication
- Session IDs in URLs
- Session IDs not invalidated after logout

**Prevention**:
- Implement multi-factor authentication
- Check against weak password lists
- Limit or delay failed login attempts
- Use server-side session management
- Invalidate session IDs after logout
- Rotate session IDs after login

## 8. Software and Data Integrity Failures
**Description**: Code and infrastructure that don't protect against integrity violations.

**Examples**:
- Auto-updates without verification
- Insecure CI/CD pipelines
- Untrusted sources for dependencies
- Insecure deserialization

**Prevention**:
- Use digital signatures to verify software
- Review code and configuration changes
- Ensure CI/CD pipeline has proper segregation
- Don't send unsigned or unencrypted data to clients
- Use integrity checking

## 9. Security Logging and Monitoring Failures
**Description**: Insufficient logging, detection, monitoring, and active response.

**Examples**:
- Not logging security events
- Logs only stored locally
- No alerting thresholds
- Log and alert messages unclear
- Logs of critical apps not monitored

**Prevention**:
- Log all authentication, access control failures
- Ensure logs are in a format for log management
- Ensure log data is encoded to prevent injection
- Effective monitoring and alerting
- Establish incident response plan

## 10. Server-Side Request Forgery (SSRF)
**Description**: Web application fetches remote resource without validating user-supplied URL.

**Examples**:
- Accessing internal-only services
- Scanning internal networks
- Reading local files
- Bypassing firewalls

**Prevention**:
- Sanitize and validate all client-supplied input data
- Enforce URL schema, port, and destination with whitelist
- Disable HTTP redirections
- Use network segmentation
- Don't send raw responses to clients
