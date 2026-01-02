<h1>Modpack Manager (MCMM)</h1>

<h2>Overview</h2>

<p>
MCMM is designed to act as a foundational platform for managing Minecraft modpacks in
containerized or server-oriented environments. The project emphasizes maintainability,
modularity, and the enforcement of consistent coding standards across all components.
</p>

<p>
Minecraft server instances managed by MCMM are created and operated using Docker images
provided by <a href="https://github.com/itzg/docker-minecraft-server" target="_blank" rel="noopener noreferrer"><strong>itzg</strong></a>. These images are widely adopted, actively maintained,
and serve as the standardized runtime layer for all Minecraft servers provisioned through MCMM.
</p>

<p>
This README intentionally provides a high-level overview only. Detailed usage,
configuration, and deployment documentation may be added as the project matures.
</p>

<hr>

<h2>Architecture</h2>

<p>
MCMM follows a modular and extensible architecture, designed to clearly separate concerns
and support future growth.
</p>

<h3>Container Runtime Layer</h3>
<p>
Minecraft servers run inside Docker containers based on itzg-provided images. This ensures
a consistent, reproducible, and well-supported execution environment for both vanilla and
modded servers.
</p>

<h3>Modpack Management Layer</h3>
<p>
MCMM is responsible for organizing, validating, and preparing modpack definitions that are
injected into the containerized server runtime.
</p>

<h3>Configuration &amp; Orchestration Layer</h3>
<p>
Server configuration, lifecycle control, and environment-specific parameters are managed
externally to the container images. This allows a clean separation between infrastructure
concerns and application logic.
</p>

<p>
This layered approach allows MCMM to remain agnostic of specific hosting environments while
still leveraging best-in-class container images for Minecraft server execution.
</p>

<hr>

<h2>Code Quality and Tooling</h2>

<p>
The repository enforces strict coding standards through automated tooling to ensure
long-term maintainability and reliability:
</p>

<ul>
  <li><strong>PHP</strong>: PSR-12 compliance enforced via PHP CodeSniffer</li>
  <li><strong>Static Analysis</strong>: PHPStan</li>
  <li><strong>JavaScript</strong>: ESLint</li>
  <li><strong>CSS</strong>: Stylelint</li>
  <li><strong>HTML</strong>: HTMLHint</li>
</ul>

<p>
All contributions are expected to pass these checks.
</p>

<hr>

<h2>Contributions</h2>

<p>
Contributions are welcome. Please ensure that all changes adhere to the established coding
standards and that all automated checks pass before submitting a pull request.
</p>

<p>
Architectural changes should remain aligned with the project’s modular and container-first
design philosophy.
</p>

<hr>

<h2>License</h2>

<p>
This project is licensed under the MIT License.<br>
See the <code>LICENSE</code> file in the repository root for full details.
</p>

<hr>

<h2>Contact</h2>

<p>
Use GitHub Issues for bug reports, feature requests, or general discussion.
</p>
