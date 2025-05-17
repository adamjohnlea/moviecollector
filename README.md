# Movie Collector

A personal movie collection application built with PHP 8.4, Twig, Tailwind CSS, and SQLite. This application allows users to search for movies using The Movie Database (TMDb) API and add them to their personal collection.

## Features

- User registration and authentication
- Integration with TMDb API
  - Efficient API usage with configuration caching
  - Support for both API key and Bearer token authentication
  - Automatic image URL generation for posters and backdrops
- Movie search functionality
  - Real-time movie search with pagination
  - Detailed movie information including posters, release dates, and descriptions
  - Visual indicators for movies already in your collection
- Personal movie collection management
- Responsive design with Tailwind CSS
- Custom logging system for error tracking and debugging
  - Detailed API request/response logging
  - Performance monitoring for external services

## Requirements

- PHP 8.4 or higher
- SQLite
- Composer
- TMDb API key (each user needs their own)
- Web server with URL rewriting support (Herd, Apache, Nginx, etc.)
- Writable permissions for cache, logs, and data directories

## Installation

1. Clone the repository:
   ```
   git clone https://github.com/yourusername/moviecollector.git
   cd moviecollector
   ```

2. Install dependencies:
   ```
   composer install
   ```

3. Initialize the database:
   ```
   php bin/init_db.php
   ```

4. Configure your web server to point to the `public` directory.

5. Ensure the following directories are writable by the web server:
   - `logs/`
   - `data/`
   - `var/cache/`

## Setting up TMDb API

1. Register for a free account at [The Movie Database](https://www.themoviedb.org/signup)
2. Once registered, go to your [account settings](https://www.themoviedb.org/settings/api)
3. Request an API key for developer use
4. After receiving approval, you'll be given both an API Key and a Read Access Token
5. Enter these credentials in the Movie Collector settings page after logging in
6. The application will use these credentials to search for movies and retrieve their details
7. Note: TMDb has rate limits, so be mindful of how frequently you make requests

## Project Structure

- `bin/` - Command line scripts for database initialization and maintenance
- `config/` - Configuration files including database and application settings
- `data/` - SQLite database files and schema
- `logs/` - Application logs for errors, warnings, and informational messages
- `public/` - Public-facing files (index.php, assets, CSS, JavaScript)
  - `uploads/` - User-uploaded content including movie posters
- `resources/` - Frontend resources (unprocessed)
- `src/` - Application source code
  - `Controllers/` - Application controllers that handle HTTP requests
  - `Core/` - Core framework files including routing and dependency injection
  - `Database/` - Database connections, queries, and migration managers
  - `Models/` - Data models representing database entities
  - `Services/` - Service classes for business logic and external API integration
- `templates/` - Twig templates organized by feature
- `var/` - Cache and other variable data generated at runtime

## Usage

1. Register for an account at https://moviecollector.test/register
2. Log in to your account
3. Go to Settings and add your TMDb API key or access token
4. Search for movies:
   - Use the search bar at the top of the page
   - Results are paginated for better performance
   - Each movie shows its poster, title, release year, and overview
   - Movies already in your collection are clearly marked
5. View and manage your movie collection

## Development

This project is developed using Herd for local development. The site URL is configured as `https://moviecollector.test`.

### Debugging

The application includes comprehensive logging for troubleshooting:
- API interactions are logged in `logs/app.log`
- Search operations include detailed request/response information
- Image URL generation is tracked for debugging display issues
- Authentication and user operations are logged for security monitoring

## Contributing

Contributions are welcome! Please feel free to submit a Pull Request.

1. Fork the repository
2. Create your feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add some amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

### Coding Standards

- Follow PSR-12 coding standards
- Use typed properties and return types
- Write unit tests for new features
- Update documentation as needed

## Technologies

- PHP 8.4
- Twig templating engine
- Tailwind CSS
- SQLite database
- The Movie Database (TMDb) API
- Symfony HTTP Foundation components

## License

This project is open-source and available under the MIT License. 