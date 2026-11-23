# IvyForms Lite – WordPress Form Builder

Public copy of **IvyForms Lite**, a drag-and-drop form builder plugin for WordPress, built with **Vue 3** and **object-oriented PHP**.

I worked on IvyForms as a Full Stack Developer in the product team at TOUCH ME SOFT (2024 – 2026). This repository mirrors the free version that is publicly available on WordPress.org and is shared as a portfolio reference, with my tech lead's approval.

> **Credits:** IvyForms is developed by the IvyForms team at Melograno Venture Studio / TOUCH ME SOFT. This is not a solo project, and the original authorship and license are kept unchanged.

## Live demo

**[▶ Try IvyForms in WordPress Playground](https://playground.wordpress.net/?plugin=ivyforms&url=%2Fwp-admin%2Fadmin.php%3Fpage%3Divyforms-forms)**

The link starts a fresh WordPress site in your browser with IvyForms installed, so there's nothing to set up and nothing is saved.

- WordPress.org: [wordpress.org/plugins/ivyforms](https://wordpress.org/plugins/ivyforms/)
- Website: [ivyforms.com](https://ivyforms.com)

## What IvyForms does

- Drag-and-drop form builder in the WordPress admin
- Field validation, conditional logic and multi-step forms
- Entries (submissions) stored in WordPress database tables, with an admin view
- Email notifications after form submission
- Integrations with third-party services
- Gutenberg block for embedding forms
- Pro version works as an add-on on top of Lite (Pro code is not included here)

## My contributions

There was no fixed module ownership in the team, so I worked across the whole product (Lite and the Pro add-on):

- **Frontend:** form-builder features in Vue 3 (Composition API, TypeScript, Pinia) with reusable components, including field validation, conditional logic and multi-step forms.
- **New fields:** added new field types end to end, from builder UI to validation, storage and rendering.
- **Backend:** features in object-oriented PHP without a framework, using namespaces with Composer (PSR-4), interfaces, MVC controllers, repositories and services with dependency injection, exposed through WordPress REST API routes.
- **Database:** custom MySQL tables, schema migrations and queries for form entries, plus work on the entries admin and email notifications.
- **Integrations:** Slack, HubSpot, Brevo, Campaign Monitor, Zapier and other third-party services.
- **AI / MCP:** built the MCP adapter on the WordPress Abilities API and the MCP Adapter package, so AI assistants can create and edit forms.
- **Quality:** wrote Playwright E2E and PHP unit tests, fixed bugs reported through support, and handled security (sanitization, nonces, capability checks) and i18n.

## Tech stack

| Area | Technologies |
|---|---|
| Frontend | Vue 3, Composition API, TypeScript, Pinia, Vite |
| Backend | PHP (OOP), WordPress REST API, Composer (PSR-4) |
| Database | MySQL (custom tables, migrations) |
| AI | WordPress Abilities API, MCP Adapter |
| Testing | Playwright (E2E), PHP unit tests |
| Workflow | Docker, Git, Jira, Scrum |

## Run it locally

1. Copy this folder to `wp-content/plugins/ivyforms` in a WordPress installation.
2. Activate **IvyForms** under **Plugins**.
3. Open **IvyForms → Add New** in the admin menu.

See `readme.txt` for the current WordPress and PHP version requirements.

## License

Distributed under the same license as the original WordPress.org release (GPL). See the license file and plugin header.

## Contact

**Marko Milinković**, Full Stack Web Developer
[milinkovic149@gmail.com](mailto:milinkovic149@gmail.com) · [LinkedIn](https://www.linkedin.com/in/marko-milinkovic-6b9573278)
