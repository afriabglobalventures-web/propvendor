// This file is for shared React components.
import React from 'react';
import { Category, Listing } from './types';

// Helper to create SVG icons
const createIcon = (path: React.ReactNode) => (props: { className?: string }) => (
  <svg
    xmlns="http://www.w3.org/2000/svg"
    viewBox="0 0 24 24"
    fill="currentColor"
    className={props.className}
  >
    {path}
  </svg>
);

// Icon Components
export const HouseIcon = createIcon(<path d="M10 20v-6h4v6h5v-8h3L12 3 2 12h3v8z" />);
export const CarIcon = createIcon(<path d="M18.92 6.01C18.72 5.42 18.16 5 17.5 5h-11c-.66 0-1.21.42-1.42 1.01L3 12v8c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-1h12v1c0 .55.45 1 1 1h1c.55 0 1-.45 1-1v-8l-2.08-5.99zM6.5 16c-.83 0-1.5-.67-1.5-1.5S5.67 13 6.5 13s1.5.67 1.5 1.5S7.33 16 6.5 16zm11 0c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5zM5 11l1.5-4.5h11L19 11H5z" />);
export const BriefcaseIcon = createIcon(<path d="M20 6h-4V4c0-1.11-.89-2-2-2h-4c-1.11 0-2 .89-2 2v2H4c-1.11 0-2 .89-2 2v11c0 1.11.89 2 2 2h16c1.11 0 2-.89 2-2V8c0-1.11-.89-2-2-2zM10 4h4v2h-4V4zm10 17H4V8h16v13z" />);
export const ToolsIcon = createIcon(<path d="M22.7 19l-9.1-9.1c.9-2.3.4-5-1.5-6.9-2-2-5-2.4-7.4-1.3L9 6.1 6.1 9 1.6 4.5C.5 6.9.9 9.9 2.9 11.9c1.9 1.9 4.6 2.4 6.9 1.5l9.1 9.1c.4.4 1 .4 1.4 0l2.4-2.4c.4-.4.4-1 0-1.4z" />);
export const WalletIcon = createIcon(<path d="M21 18v1c0 1.1-.9 2-2 2H5c-1.11 0-2-.9-2-2V5c0-1.1.89-2 2-2h14c1.1 0 2 .9 2 2v1h-9c-1.11 0-2 .9-2 2v8c0 1.1.89 2 2 2h9zm-9-2h10V8H12v8zm4-2.5c-.83 0-1.5-.67-1.5-1.5s.67-1.5 1.5-1.5 1.5.67 1.5 1.5-.67 1.5-1.5 1.5z" />);
export const LocationIcon = createIcon(<path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7zm0 9.5c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z" />);
export const CategoryIcon = createIcon(<path d="M12 2l-5.5 9h11L12 2zm0 3.84L13.93 9h-3.86L12 5.84zM17.5 13c-2.49 0-4.5 2.01-4.5 4.5s2.01 4.5 4.5 4.5 4.5-2.01 4.5-4.5-2.01-4.5-4.5-4.5zm0 7c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5zM6.5 13C4.01 13 2 15.01 2 17.5S4.01 22 6.5 22s4.5-2.01 4.5-4.5S8.99 13 6.5 13zm0 7c-1.38 0-2.5-1.12-2.5-2.5s1.12-2.5 2.5-2.5 2.5 1.12 2.5 2.5-1.12 2.5-2.5 2.5z" />);
export const PriceIcon = createIcon(<path d="M11.8 10.9c-2.27-.59-3-1.2-3-2.15 0-.96.74-1.75 2.09-1.75 1.35 0 2.09.8 2.1 1.75h2.11c-.02-1.62-1.2-2.94-3.13-3.26V4h-2.12v1.31c-2.33.4-3.96 2.06-3.96 4.19 0 2.62 2.16 3.96 4.5 4.58 2.7.7 3.32 1.33 3.32 2.24 0 1.02-.81 1.83-2.33 1.83-1.52 0-2.34-.8-2.34-1.83H6.1c.02 1.71 1.4 3.14 3.59 3.48V20h2.12v-1.27c2.14-.37 3.78-1.95 3.78-4.16 0-2.9-2.39-4.28-4.71-4.97z" />);
export const UploadIcon = createIcon(<path d="M9 16h6v-6h4l-7-7-7 7h4v6zm-4 2h14v2H5v-2z" />);
export const VerifyIcon = createIcon(<path fillRule="evenodd" d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zM9.29 16.29L5.7 12.7c-.39-.39-.39-1.02 0-1.41.39-.39 1.02-.39 1.41 0L10 14.17l6.88-6.88c.39-.39 1.02-.39 1.41 0 .39.39.39 1.02 0 1.41l-7.59 7.59c-.38.39-1.02.39-1.41 0z" clipRule="evenodd"/>);
export const PaidIcon = createIcon(<path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z" />);

// General Button Component
export const Button: React.FC<{
  children: React.ReactNode;
  onClick?: () => void;
  className?: string;
  variant?: 'primary' | 'secondary';
}> = ({ children, onClick, className = '', variant = 'primary' }) => {
  const baseClasses = 'px-6 py-3 font-semibold rounded-lg shadow-md transition-transform transform hover:scale-105';
  const variantClasses = variant === 'primary'
    ? 'bg-gradient-to-r from-pv-blue to-pv-green text-white'
    : 'bg-pv-white text-pv-blue border border-pv-blue';

  return (
    <button onClick={onClick} className={`${baseClasses} ${variantClasses} ${className}`}>
      {children}
    </button>
  );
};

// Header Component
export const Header: React.FC = () => (
  <header className="bg-pv-white shadow-sm sticky top-0 z-50">
    <div className="container mx-auto px-4 sm:px-6 lg:px-8">
      <div className="flex items-center justify-between h-20">
        <div className="flex-shrink-0">
          <a href="#" className="text-2xl font-bold text-pv-blue">
            PropVendor
          </a>
        </div>
        <div className="hidden md:flex md:items-center md:space-x-8">
          <a href="#" className="text-pv-text-secondary hover:text-pv-blue">Home</a>
          <a href="#" className="text-pv-text-secondary hover:text-pv-blue">Listings</a>
          <a href="#" className="text-pv-text-secondary hover:text-pv-blue">About</a>
          <a href="#" className="text-pv-text-secondary hover:text-pv-blue">Contact</a>
        </div>
        <div className="flex items-center space-x-4">
          <WalletIcon className="w-7 h-7 text-pv-text-secondary cursor-pointer hover:text-pv-blue" />
          <Button variant="secondary" className="hidden sm:block !py-2 !px-5">Login</Button>
          <Button variant="primary" className="!py-2 !px-5">Register</Button>
        </div>
      </div>
    </div>
  </header>
);

// Footer Component
export const Footer: React.FC = () => (
  <footer className="bg-pv-light-gray">
    <div className="container mx-auto py-12 px-4 sm:px-6 lg:px-8">
      <div className="xl:grid xl:grid-cols-3 xl:gap-8">
        <div className="space-y-8 xl:col-span-1">
          <h2 className="text-3xl font-bold text-pv-blue">PropVendor</h2>
          <p className="text-pv-text-secondary text-base">
            The most secure and trusted marketplace for properties, vehicles, and services in Nigeria.
          </p>
        </div>
        <div className="mt-12 grid grid-cols-2 gap-8 xl:mt-0 xl:col-span-2">
          <div className="md:grid md:grid-cols-2 md:gap-8">
            <div>
              <h3 className="text-sm font-semibold text-pv-text-secondary tracking-wider uppercase">Solutions</h3>
              <ul className="mt-4 space-y-4">
                <li><a href="#" className="text-base text-pv-text-secondary hover:text-pv-text">Properties</a></li>
                <li><a href="#" className="text-base text-pv-text-secondary hover:text-pv-text">Vehicles</a></li>
                <li><a href="#" className="text-base text-pv-text-secondary hover:text-pv-text">Rentals</a></li>
              </ul>
            </div>
            <div className="mt-12 md:mt-0">
              <h3 className="text-sm font-semibold text-pv-text-secondary tracking-wider uppercase">Support</h3>
              <ul className="mt-4 space-y-4">
                <li><a href="#" className="text-base text-pv-text-secondary hover:text-pv-text">Pricing</a></li>
                <li><a href="#" className="text-base text-pv-text-secondary hover:text-pv-text">Help Center</a></li>
                <li><a href="#" className="text-base text-pv-text-secondary hover:text-pv-text">Disputes</a></li>
              </ul>
            </div>
          </div>
          <div className="md:grid md:grid-cols-2 md:gap-8">
            <div>
              <h3 className="text-sm font-semibold text-pv-text-secondary tracking-wider uppercase">Company</h3>
              <ul className="mt-4 space-y-4">
                <li><a href="#" className="text-base text-pv-text-secondary hover:text-pv-text">About Us</a></li>
                <li><a href="#" className="text-base text-pv-text-secondary hover:text-pv-text">Blog</a></li>
                <li><a href="#" className="text-base text-pv-text-secondary hover:text-pv-text">Careers</a></li>
              </ul>
            </div>
            <div className="mt-12 md:mt-0">
              <h3 className="text-sm font-semibold text-pv-text-secondary tracking-wider uppercase">Legal</h3>
              <ul className="mt-4 space-y-4">
                <li><a href="#" className="text-base text-pv-text-secondary hover:text-pv-text">Terms of Service</a></li>
                <li><a href="#" className="text-base text-pv-text-secondary hover:text-pv-text">Privacy Policy</a></li>
              </ul>
            </div>
          </div>
        </div>
      </div>
      <div className="mt-12 border-t border-gray-200 pt-8">
        <p className="text-base text-pv-text-secondary xl:text-center">&copy; {new Date().getFullYear()} PropVendor Technologies. All rights reserved.</p>
      </div>
    </div>
  </footer>
);

// Category Card Component
export const CategoryCard: React.FC<{ category: Category }> = ({ category }) => (
  <div className="flex flex-col items-center justify-center p-6 bg-pv-white rounded-2xl shadow-md hover:shadow-lg transition-shadow cursor-pointer">
    <div className="bg-pv-light-gray rounded-full p-4">
      <category.icon className="w-8 h-8 text-pv-blue" />
    </div>
    <p className="mt-4 font-semibold text-pv-text">{category.name}</p>
  </div>
);

// Listing Card Component
export const ListingCard: React.FC<{ listing: Listing; isDeal?: boolean }> = ({ listing, isDeal = false }) => (
  <div className="bg-pv-white rounded-2xl shadow-md overflow-hidden group transform hover:-translate-y-2 transition-transform duration-300">
    <div className="relative">
      <img className="w-full h-48 object-cover" src={listing.image} alt={listing.title} />
       {isDeal && (
        <div className="absolute top-0 right-0 m-3 px-3 py-1 bg-pv-green text-white text-xs font-bold rounded-full">
          DEAL
        </div>
      )}
      <div className="absolute inset-0 bg-black bg-opacity-20 group-hover:bg-opacity-10 transition-colors"></div>
    </div>
    <div className="p-4">
      <h3 className="font-semibold text-lg text-pv-text truncate">{listing.title}</h3>
      <p className="text-pv-text-secondary text-sm flex items-center mt-1">
        <LocationIcon className="w-4 h-4 mr-1.5" />
        {listing.location}
      </p>
      <div className="flex items-center justify-between mt-4">
        <p className="font-bold text-xl text-pv-blue">
          ₦{listing.price.toLocaleString()}
        </p>
         <span className="text-xs font-medium text-white bg-blue-100 text-pv-blue py-1 px-3 rounded-full">
           Secured by PropVendor
         </span>
      </div>
    </div>
  </div>
);
