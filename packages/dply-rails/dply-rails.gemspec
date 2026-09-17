Gem::Specification.new do |spec|
  spec.name = "dply-rails"
  spec.version = "0.1.0"
  spec.summary = "Active Job on Cloudflare Queues for Rails apps deployed as dply Edge containers."
  spec.authors = ["dply"]
  spec.license = "MIT"
  spec.files = Dir["lib/**/*.rb", "README.md"]
  spec.require_paths = ["lib"]
  spec.required_ruby_version = ">= 3.1"
  spec.add_dependency "activejob", ">= 7.0"
  spec.add_dependency "railties", ">= 7.0"
end
